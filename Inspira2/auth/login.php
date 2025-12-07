<?php
session_start();
require_once "../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    // Fetch user
    $stmt = $pdo->prepare("SELECT * FROM Users_SP WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user["password_hash"])) {

        // Block disabled accounts
        if ($user["role"] === "disabled") {
            echo "This account has been disabled.";
            exit();
        }

        // Store all needed session data
        $_SESSION["user_id"] = $user["user_id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["role"] = $user["role"];

        // Update last_login timestamp
        $update = $pdo->prepare("UPDATE Users_SP SET last_login = NOW() WHERE user_id = ?");
        $update->execute([$user["user_id"]]);

        // Redirect
        if ($user["role"] === "admin") {
            header("Location: ../admin/admin_dashboard.php");
        } else {
            header("Location: ../User/dashboard.php");
        }
        exit();

    } else {
        echo "Invalid credentials.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login – Inspira</title>

<link rel="stylesheet" href="https://use.typekit.net/ybt0vjv.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body {
        background: #F7F5EF;
        font-family: 'Poppins', sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        margin: 0;
    }

    .login-container {
        background: #fff;
        padding: 40px;
        width: 380px;
        border-radius: 18px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        text-align: center;
    }

    .login-title {
        font-family:"Runtime","Poppins",sans-serif;
        font-size: 1.9rem;
        margin-bottom: 10px;
        font-weight: 600;
        color: #000;
    }

    .login-sub {
        font-size: 0.95rem;
        color: #6b6b6b;
        margin-bottom: 25px;
    }

    .login-input {
        width: 100%;
        padding: 12px 16px;
        margin-bottom: 15px;
        border-radius: 12px;
        border: 1.5px solid #d4cfc5;
        background: #faf7f2;
        transition: 0.2s ease-in-out;
        font-size: 0.95rem;
    }

    .login-input:focus {
        outline: none;
        border-color: #9F9A7F;
        background: #fff;
    }

    .login-btn {
        width: 100%;
        padding: 12px;
        border: none;
        background: #9F9A7F;
        color: #000;
        font-weight: 600;
        border-radius: 12px;
        cursor: pointer;
        transition: 0.2s ease-in-out;
        font-size: 1rem;
    }

    .login-btn:hover {
        background: #8d876f;
    }

    .signup-link {
        margin-top: 15px;
        font-size: 0.9rem;
    }

    .signup-link a {
        color: #9F9A7F;
        text-decoration: none;
        font-weight: 600;
    }

    .signup-link a:hover {
        text-decoration: underline;
    }
</style>
</head>

<body>

<div class="login-container">
    <h2 class="login-title">Welcome to Inspira</h2>
    <p class="login-sub">Log in to explore your inspirations</p>

    <form method="POST">
        <input type="email" name="email" class="login-input" placeholder="Email" required>
        <input type="password" name="password" class="login-input" placeholder="Password" required>
        <button type="submit" class="login-btn">Login</button>
    </form>

    <p class="signup-link">
        Don’t have an account?
        <a href="register.php">Sign Up</a>
    </p>
</div>

</body>
</html>
