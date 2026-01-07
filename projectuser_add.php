<?php
include "utility_functions.php";

$sessionid = $_GET["sessionid"];
verify_session($sessionid);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $firstname = trim($_POST["firstname"]);
    $lastname  = trim($_POST["lastname"]);
    $phone     = trim($_POST["phone"]);
    $usertype  = isset($_POST["usertype"]) ? $_POST["usertype"] : "";
    $status    = isset($_POST["status"]) ? $_POST["status"] : "";
    $password  = trim($_POST["password"]);
	$country   = trim($_POST["country"]);
	$state   = trim($_POST["state"]);	

    if ($firstname === "" || $lastname === "" || $phone === "" || $usertype === "" || $status === "" || $password === "") {
        $message = "Error: All fields are required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $message = "Error: Phone number must be exactly 10 digits.";
    } elseif (!in_array($usertype, array("Regular", "Admin", "Hybrid"))) {
        $message = "Error: Invalid user type.";
    } elseif (!in_array($status, array("domestic", "foreign"))) {
        $message = "Error: Invalid customer type.";
    } else {

        // Dates based on user type
        if ($usertype === "Regular") {
            $admin_date = "NULL";
            $reg_date   = "SYSDATE";
        } elseif ($usertype === "Admin") {
            $admin_date = "SYSDATE";
            $reg_date   = "NULL";
        } else { // Hybrid
            $admin_date = "SYSDATE";
            $reg_date   = "SYSDATE";
        }

        // Diamond default = 'N'
        $diamond = "N";
		
		// Call stored procedure get_new_username(p_first, p_last, p_user OUT)
		$username = null;

		// Use the same connection your execute_sql_in_oracle() uses.
		// In many class templates it's a global like $connection.
		global $connection;

		$stmt = oci_parse($connection, "BEGIN get_new_username(:p_first, :p_last, :p_user); END;");
		oci_bind_by_name($stmt, ":p_first", $firstname);
		oci_bind_by_name($stmt, ":p_last",  $lastname);
		oci_bind_by_name($stmt, ":p_user",  $username, 20); // OUT param buffer

		oci_execute($stmt);
		oci_free_statement($stmt);

		// now $username contains something like 'JD000123'


        $sql_insert_user = "
            INSERT INTO projectUser
                (username, password, u_firstName, u_lastName, userType,
                 phone_number, status, diamond_customer, admin_strDate, reg_registrationDate, country, state)
            VALUES
                ('$username', '$password', '$firstname', '$lastname', '$usertype',
                 '$phone', '$status', '$diamond', $admin_date, $reg_date, '$country', '$state')
        ";

        $res_ins = execute_sql_in_oracle($sql_insert_user);
        if ($res_ins["flag"] == false) {
            display_oracle_error_message($res_ins["cursor"]);
            $message = "Error: Failed to add new customer.";
        } else {
            $message = "Success: New customer created. Username: $username";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Add New Customer</title>
</head>
<body>
  <h2>Add New Customer</h2>
  <p>Session ID: <?php echo htmlspecialchars($sessionid); ?></p>

  <?php
    if ($message !== "") {
        echo "<p><strong>$message</strong></p>";
    }
  ?>

  <form method="post" action="projectuser_add.php?sessionid=<?php echo htmlspecialchars($sessionid); ?>">
    First Name: <input type="text" name="firstname" maxlength="20"><br><br>
    Last Name: <input type="text" name="lastname" maxlength="20"><br><br>
    Phone Number (10 digits): <input type="text" name="phone" maxlength="10"><br><br>

    User Type:
    <select name="usertype">
      <option value=""></option>
      <option value="Regular">Regular</option>
      <option value="Admin">Admin</option>
      <option value="Hybrid">Hybrid</option>
    </select><br><br>

    Customer Type:
    <select name="status">
      <option value=""></option>
      <option value="domestic">domestic</option>
      <option value="foreign">foreign</option>
    </select><br><br>

    Password: <input type="password" name="password" maxlength="20"><br><br>

	Country: <input type="text" name="country" maxlength="2"><br><br>
	State: <input type="text" name="state" maxlength="2"><br><br>
	

    <input type="submit" value="Add Customer">
  </form>

  <br>
  <form method="post" action="homepage.php?sessionid=<?php echo htmlspecialchars($sessionid); ?>">
    <input type="submit" value="Back to Homepage">
  </form>
</body>
</html>
