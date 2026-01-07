<?php
include "utility_functions.php";

$sessionid = $_GET["sessionid"];
verify_session($sessionid);

// Get the user info
$sql = "
  SELECT 
      pu.userType,
      pu.username,
      pu.u_firstName,
      pu.u_lastName,
      us.sessionDate
  FROM projectUser pu
  JOIN UserSession us
    ON pu.username = us.s_username
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

  $userType    = $values[0];
  $username    = $values[1];
  $firstname   = $values[2];
  $lastname    = $values[3];
  $sessiondate = $values[4];

  echo "<h2>Welcome, $firstname $lastname</h2>";
  echo "Session started on: $sessiondate <br>";
  echo "Access Type: $userType <br>";
  echo "<hr>";

  echo "<h3>Customer Options</h3>";
  echo "<ul>";
  echo "<li><a href=\"regular_user.php?sessionid=$sessionid&view=personal\">Customer Personal Information</a></li>";
  echo "<li><a href=\"regular_user.php?sessionid=$sessionid&view=reservation\">Customer Reservation Information</a></li>";
  echo "<li><a href=\"customer_flight_reservation.php?sessionid=$sessionid\">Customer Flight Reservation</a></li>";
  echo "</ul>";

  if ($userType === "Hybrid" || $userType === "Admin") {
    echo "<hr>";
    echo "<h3>Admin Options</h3>";
    echo("<UL>
      <li><a href=\"projectuser_add.php?sessionid=$sessionid\">Add New Customer</a></li>
      <li><a href=\"projectuser_update_delete.php?sessionid=$sessionid\">Update / Delete Customers</a></li>
      <li><a href=\"projectuser_set_grade.php?sessionid=$sessionid\">Enter Seating Grades</a></li>
     </UL>");
  }

  echo "<hr>";
  echo "<a href=\"projectuser_change_password.php?sessionid=$sessionid\">Change Password</a><br><br>";
  echo "Click <a href=\"logout_action.php?sessionid=$sessionid\">here</a> to Logout.";
}
?>
