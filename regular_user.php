<?php
include "utility_functions.php";

$sessionid = $_GET["sessionid"];
verify_session($sessionid);

// Which section to show: 'personal' or 'reservation'
$view = isset($_GET["view"]) ? $_GET["view"] : "personal";

// Get the user info
$sql = "
  SELECT
      pu.userType,
      pu.username,
      pu.u_firstName,
      pu.u_lastName,
      us.sessionDate,
      pu.phone_number,
      pu.status,
      pu.diamond_customer,
      pu.admin_strDate,
      pu.reg_registrationDate
  FROM projectUser pu
  JOIN UserSession us ON pu.username = us.s_username
  WHERE us.sessionId = '$sessionid'
";

$result_array = execute_sql_in_oracle($sql);
$result = $result_array["flag"];
$cursor = $result_array["cursor"];

if ($result == false) {
  display_oracle_error_message($cursor);
  die("Client Query Failed.");
}

if ($values = oci_fetch_array($cursor)) {
  oci_free_statement($cursor);

  $userType             = $values[0];
  $username             = $values[1];
  $firstname            = $values[2];
  $lastname             = $values[3];
  $sessiondate          = $values[4];
  $phone_number         = $values[5];
  $status               = $values[6];
  $diamond_customer     = $values[7];
  $admin_strDate        = $values[8];
  $reg_registrationDate = $values[9];

  // PERSONAL INFO VIEW
  if ($view === "personal") {

    echo "<h2>Customer Personal Information</h2>";
    echo "Username: $username <br>";
    echo "Firstname: $firstname <br>";
    echo "Lastname: $lastname <br>";
    echo "Phone Number: $phone_number <br>";
    echo "Access Type (userType): $userType <br>";
    echo "Customer Status (domestic/foreign): $status <br>";
    echo "Diamond Customer (Y/N): $diamond_customer <br>";
    echo "Admin Start Date: " . ($admin_strDate ? $admin_strDate : 'N/A') . "<br>";
    echo "Regular Registration Date: " . ($reg_registrationDate ? $reg_registrationDate : 'N/A') . "<br>";
    echo "Session started on: $sessiondate <br>";

  }
  // RESERVATION INFO VIEW
  elseif ($view === "reservation") {

    // Get total reservations from the VIEW
    $sql_view = "
      SELECT total_reservations
      FROM customer_reservation_view
      WHERE username = '$username'
    ";

    $view_res    = execute_sql_in_oracle($sql_view);
    $view_flag   = $view_res["flag"];
    $view_cursor = $view_res["cursor"];

    if ($view_flag == false) {
      display_oracle_error_message($view_cursor);
      die("View Query Failed.");
    }

    $total_reservations_from_view = 0;

    if ($row_view = oci_fetch_array($view_cursor)) {
      $total_reservations_from_view = $row_view[0];
    }
    oci_free_statement($view_cursor);

    echo "<h2>Customer Reservation Information</h2>";

    // Stats query (uses Reserves + Flight, assumes seating_grade exists in Reserves)
    $sql_stats = "
      SELECT
        -- Total reservations (not used now, we use the view instead)
        (SELECT COUNT(*)
           FROM Reserves r
          WHERE r.username = '$username') AS total_reservations,

        -- Upcoming reservations (flight date today or later)
        (SELECT COUNT(*)
           FROM Reserves r
           JOIN Flight f ON r.flightId = f.flightId
          WHERE r.username = '$username'
            AND f.flightDate >= TRUNC(SYSDATE)) AS upcoming_reservations,

        -- Average monthly flights for past 12 months (including current month)
        (SELECT NVL(COUNT(*), 0) / 12
           FROM Reserves r
           JOIN Flight f ON r.flightId = f.flightId
          WHERE r.username = '$username'
            AND f.flightDate >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -11)
            AND f.flightDate <  ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)
        ) AS avg_monthly_flights,

        -- Diamond Customer Score = sum(seating_grade) / total reservations
        (SELECT CASE
                  WHEN COUNT(*) = 0 THEN NULL
                  ELSE SUM(r.seating_grade) / COUNT(*)
                END
           FROM Reserves r
          WHERE r.username = '$username'
        ) AS diamond_score
      FROM dual
    ";

    $stats_array  = execute_sql_in_oracle($sql_stats);
    $stats_result = $stats_array["flag"];
    $stats_cursor = $stats_array["cursor"];

    if ($stats_result == false) {
      display_oracle_error_message($stats_cursor);
      die("Reservation Stats Query Failed.");
    }

    $total_reservations    = 0;
    $upcoming_reservations = 0;
    $avg_monthly_flights   = 0;
    $diamond_score         = null;

    if ($stats_values = oci_fetch_array($stats_cursor)) {
      oci_free_statement($stats_cursor);

      $total_reservations    = $stats_values[0]; // kept but not displayed
      $upcoming_reservations = $stats_values[1];
      $avg_monthly_flights   = $stats_values[2];
      $diamond_score         = $stats_values[3];
    }

    // Use the value from the VIEW here
    echo "Total Reservations: $total_reservations_from_view <br>";
    echo "Upcoming Reservations (today or later): $upcoming_reservations <br>";
    echo "Average Monthly Flights (past 12 months): " . number_format($avg_monthly_flights, 2) . "<br>";
    echo "Diamond Customer Score: " . ($diamond_score !== null ? number_format($diamond_score, 2) : 'N/A') . "<br>";



    
    // List all the flights reserved by the customer (View only)
    echo("<br>");
    echo("$username's Reserved Flight List:");
    echo("<br>");

    // Query reserved flights for this customer
    $sql = "SELECT
            R.flightId,
            F.airlinecode,
            F.flightnumber,
            F.flightdate,
            R.seating_grade
            FROM reserves R
            JOIN flight F ON R.flightId = F.flightID
            JOIN flightroute FR ON FR.airlinecode = F.airlinecode AND FR.flightnumber = F.flightnumber
            WHERE R.username = '$username' AND F.flightdate >= TRUNC(SYSDATE)";

    $result_array = execute_sql_in_oracle($sql);
    $result = $result_array["flag"];
    $cursor = $result_array["cursor"];

    if ($result == false) {
      display_oracle_error_message($cursor);
      die("Client Query Failed.");
    }

    // Table header
    echo "<table border='1' cellspacing='0' cellpadding='5'>";
    echo "<tr>
            <th>Flight ID</th>
            <th>Airline Code</th>
            <th>Flight Number</th>
            <th>Flight Date</th>
            <th>Seating Grade</th>
          </tr>";

    // Fetch rows
    while ($values = oci_fetch_array($cursor)) {
      echo("<tr>" . 
      "<td>$values[0]</td> <td>$values[1]</td> <td>$values[2]</td> <td>$values[3]</td> <td>$values[4]</td>". 
      "</tr>");
      }

    echo "</table>";

    oci_free_statement($cursor);
  }

  echo "<br><br>";
  echo "Click <a href=\"logout_action.php?sessionid=$sessionid\">here</a> to Logout.";
}
?>
