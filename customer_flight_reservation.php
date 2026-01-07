<?php
include "utility_functions.php";

$sessionid = $_GET["sessionid"];
verify_session($sessionid);

// Get current user info (mainly username)
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
  die("User Query Failed.");
}

if (!($values = oci_fetch_array($cursor))) {
  oci_free_statement($cursor);
  die("No user found for this session.");
}
oci_free_statement($cursor);

$userType    = $values[0];
$username    = $values[1];
$firstname   = $values[2];
$lastname    = $values[3];
$sessiondate = $values[4];

$message = "";

// ----------------------
// Handle reservation POST
// ----------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = isset($_POST["reservation_type"]) ? $_POST["reservation_type"] : "";

  if ($action === "single") {
    $flightId = trim($_POST["single_flight_id"]);

    if ($flightId === "") {
        $message = "Error: Please enter a flight ID for single reservation.";
    } else {
        // PL/SQL block that does locking + checks + insert atomically
        $plsql = "
        DECLARE
            v_flightDate  Flight.flightDate%TYPE;
            v_capacity    Flight.capacity%TYPE;
            v_reserved    NUMBER;
            v_existing    NUMBER;
        BEGIN
            -- Lock the flight row
            SELECT flightDate, capacity
            INTO   v_flightDate, v_capacity
            FROM   Flight
            WHERE  flightId = '$flightId'
            FOR UPDATE;

            -- Rule 1: flight must be today or later
            IF TO_CHAR(v_flightDate, 'YYYYMMDD') < TO_CHAR(TRUNC(SYSDATE), 'YYYYMMDD') THEN
                raise_application_error(-20001, 'Cannot reserve a flight in the past.');
            END IF;

            -- Seats currently reserved (under the same lock)
            SELECT COUNT(*)
            INTO   v_reserved
            FROM   Reserves
            WHERE  flightId = '$flightId';

            IF v_capacity - v_reserved <= 0 THEN
                raise_application_error(-20002, 'No seats available on this flight.');
            END IF;

            -- Check if this user already reserved this flight
            SELECT COUNT(*)
            INTO   v_existing
            FROM   Reserves
            WHERE  flightId = '$flightId'
              AND  username = '$username';

            IF v_existing > 0 THEN
                raise_application_error(-20003, 'You have already reserved this flight.');
            END IF;

            -- All good: insert reservation with seating_grade = 0
            INSERT INTO Reserves (flightId, username, seating_grade)
            VALUES ('$flightId', '$username', 0);

            COMMIT;
        END;
        ";

        $res = execute_sql_in_oracle($plsql);
        if ($res["flag"] == false) {
            // Get Oracle error and show user-friendly message
            $err = oci_error($res["cursor"]);
            // Strip the ORA-200xx prefix if you want
            $message = "Single-flight reservation failed: " . htmlspecialchars($err["message"]);
        } else {
            $message = "Success: Single-flight reservation for Flight '$flightId' completed.";
        }
    }
}


  } elseif ($action === "multi") {
    $f1 = trim($_POST["multi_flight_id1"]);
    $f2 = trim($_POST["multi_flight_id2"]);
    $f3 = trim($_POST["multi_flight_id3"]);

    $flights = array();
    if ($f1 !== "") $flights[] = $f1;
    if ($f2 !== "") $flights[] = $f2;
    if ($f3 !== "") $flights[] = $f3;

    if (count($flights) < 2) {
        $message = "Error: Please enter at least two flights for a multi-flight reservation.";
    } else {
        // You can keep your existing PHP checks here:
        // - same date
        // - not in the past
        // - RoutePrecedence chain is valid
        // Once those pass, we enforce capacity + duplicate + atomicity with locks.

        // Generate PL/SQL block dynamically for the chosen flights
        $fid_list = $flights; // already filtered
        // To avoid deadlocks, sort them so all sessions lock in same order
        sort($fid_list, SORT_STRING);

        // Build some PL/SQL to handle 1-3 flights generically
        $plsql = "DECLARE
  TYPE t_fid_tab IS TABLE OF VARCHAR2(10) INDEX BY PLS_INTEGER;
  v_fids t_fid_tab;
  v_capacity  NUMBER;
  v_reserved  NUMBER;
  v_existing  NUMBER;
  v_fdate     DATE;
BEGIN
";

        // Initialize array in PL/SQL
        $idx = 1;
        foreach ($fid_list as $fid) {
            $plsql .= "  v_fids($idx) := '$fid';\n";
            $idx++;
        }

        $count = count($fid_list);
        $plsql .= "
  -- Lock flights and check capacity & duplicate reservation under the lock
  FOR i IN 1..$count LOOP
    SELECT flightDate, capacity
    INTO   v_fdate, v_capacity
    FROM   Flight
    WHERE  flightId = v_fids(i)
    FOR UPDATE;

    -- Date check: today or later (safety check)
    IF TO_CHAR(v_fdate, 'YYYYMMDD') < TO_CHAR(TRUNC(SYSDATE), 'YYYYMMDD') THEN
      raise_application_error(-20021, 'Cannot reserve a flight in the past: ' || v_fids(i));
    END IF;

    SELECT COUNT(*)
    INTO   v_reserved
    FROM   Reserves
    WHERE  flightId = v_fids(i);

    IF v_capacity - v_reserved <= 0 THEN
      raise_application_error(-20022, 'No seats available on flight ' || v_fids(i));
    END IF;

    SELECT COUNT(*)
    INTO   v_existing
    FROM   Reserves
    WHERE  flightId = v_fids(i)
      AND  username = '$username';

    IF v_existing > 0 THEN
      raise_application_error(-20023, 'You have already reserved flight ' || v_fids(i));
    END IF;
  END LOOP;

  -- All checks passed under locks: insert all reservations
  FOR i IN 1..$count LOOP
    INSERT INTO Reserves (flightId, username, seating_grade)
    VALUES (v_fids(i), '$username', 0);
  END LOOP;

  COMMIT;
END;
";

        $res = execute_sql_in_oracle($plsql);
        if ($res["flag"] == false) {
            $err = oci_error($res["cursor"]);
            $message = "Multi-flight reservation failed: " . htmlspecialchars($err["message"]);
        } else {
            $message = "Success: Multi-flight reservation completed for flights: " . implode(", ", $flights);
        }
    }
}


// ----------------------
// Search filters for flights
// ----------------------
$search_airline = isset($_GET["search_airline"]) ? trim($_GET["search_airline"]) : "";
$search_fnum    = isset($_GET["search_flightnum"]) ? trim($_GET["search_flightnum"]) : "";
$search_date    = isset($_GET["search_date"]) ? trim($_GET["search_date"]) : ""; // expect YYYY-MM-DD

$where = array();
if ($search_airline !== "") {
  $where[] = "f.airlineCode = '" . $search_airline . "'";
}
if ($search_fnum !== "") {
  // numeric
  $where[] = "f.flightNumber = " . intval($search_fnum);
}
if ($search_date !== "") {
  $where[] = "f.flightDate = TO_DATE('" . $search_date . "', 'YYYY-MM-DD')";
}

$where_sql = "";
if (count($where) > 0) {
  $where_sql = "WHERE " . implode(" AND ", $where);
}

// Query flights with available seats
$sql_flights = "
  SELECT
    f.flightId,
    f.airlineCode,
    f.flightNumber,
    f.flightDate,
    f.capacity,
    (f.capacity - NVL(r.cnt, 0)) AS available_seats
  FROM Flight f
  LEFT JOIN (
    SELECT flightId, COUNT(*) AS cnt
    FROM Reserves
    GROUP BY flightId
  ) r ON f.flightId = r.flightId
  $where_sql
  ORDER BY f.flightDate, f.airlineCode, f.flightNumber
";

$arr_fl = execute_sql_in_oracle($sql_flights);
if ($arr_fl["flag"] == false) {
  display_oracle_error_message($arr_fl["cursor"]);
  die("Flight List Query Failed.");
}
$cur_fl = $arr_fl["cursor"];

?>
<!DOCTYPE html>
<html>
<head>
  <title>Customer Flight Reservation</title>
</head>
<body>
  <h2>Customer Flight Reservation Page</h2>
  <p>
    Customer: <?php echo htmlspecialchars($firstname . " " . $lastname . " (" . $username . ")"); ?><br>
    Session started on: <?php echo $sessiondate; ?>
  </p>

  <?php
    if ($message !== "") {
      echo "<p><strong>$message</strong></p>";
    }
  ?>

  <hr>

  <h3>Search Flights</h3>
  <form method="get" action="customer_flight_reservation.php">
    <input type="hidden" name="sessionid" value="<?php echo htmlspecialchars($sessionid); ?>">
    Airline Code (2 letters): <input type="text" name="search_airline" value="<?php echo htmlspecialchars($search_airline); ?>"> &nbsp;
    Flight Number: <input type="text" name="search_flightnum" value="<?php echo htmlspecialchars($search_fnum); ?>"> &nbsp;
    Flight Date (YYYY-MM-DD): <input type="text" name="search_date" value="<?php echo htmlspecialchars($search_date); ?>"> &nbsp;
    <input type="submit" value="Search">
  </form>

  <h3>Available Flights</h3>
  <table border="1" cellpadding="5" cellspacing="0">
    <tr>
      <th>Flight ID</th>
      <th>Airline</th>
      <th>Flight Number</th>
      <th>Flight Date</th>
      <th>Capacity</th>
      <th>Available Seats</th>
    </tr>
    <?php
      $has_rows = false;
      while ($row = oci_fetch_array($cur_fl)) {
        $has_rows = true;
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row[0]) . "</td>";
        echo "<td>" . htmlspecialchars($row[1]) . "</td>";
        echo "<td>" . htmlspecialchars($row[2]) . "</td>";
        echo "<td>" . htmlspecialchars($row[3]) . "</td>";
        echo "<td>" . htmlspecialchars($row[4]) . "</td>";
        echo "<td>" . htmlspecialchars($row[5]) . "</td>";
        echo "</tr>";
      }
      oci_free_statement($cur_fl);

      if (!$has_rows) {
        echo "<tr><td colspan=\"6\">No flights match your search criteria.</td></tr>";
      }
    ?>
  </table>

  <hr>

  <h3>Single-flight Reservation</h3>
  <form method="post" action="customer_flight_reservation.php?sessionid=<?php echo htmlspecialchars($sessionid); ?>">
    <input type="hidden" name="reservation_type" value="single">
    Flight ID: <input type="text" name="single_flight_id">
    <input type="submit" value="Reserve Single Flight">
  </form>

  <h3>Multi-flight Reservation (up to 3 flights, in sequence)</h3>
  <form method="post" action="customer_flight_reservation.php?sessionid=<?php echo htmlspecialchars($sessionid); ?>">
    <input type="hidden" name="reservation_type" value="multi">
    Flight ID 1: <input type="text" name="multi_flight_id1"><br>
    Flight ID 2: <input type="text" name="multi_flight_id2"><br>
    Flight ID 3: <input type="text" name="multi_flight_id3"><br>
    <input type="submit" value="Reserve Multi-flight Sequence">
  </form>

  <br><br>
  Click <a href="logout_action.php?sessionid=<?php echo htmlspecialchars($sessionid); ?>">here</a> to Logout.
</body>
</html>
