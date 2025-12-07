<?php
require_once "../includes/db.php";


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST["username"];
    $email = $_POST["email"];
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("INSERT INTO Users_SP (username, email, password_hash) VALUES (?, ?, ?)");
        
        $stmt->execute([$username, $email, $password]);
        header("Location: login.php");
        exit();
        
    } catch (PDOException $e) {
        echo "❌ Error inserting user: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up – Inspira</title>

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

    .signup-container {
        background: #fff;
        padding: 40px;
        width: 420px;
        border-radius: 18px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.08);
        text-align: center;
    }

    .signup-title {
        font-family:"Runtime","Poppins",sans-serif;
        font-size: 1.9rem;
        margin-bottom: 10px;
        font-weight: 600;
        color: #000;
    }

    .signup-sub {
        font-size: 0.95rem;
        color: #6b6b6b;
        margin-bottom: 25px;
    }

    .signup-input {
        width: 100%;
        padding: 12px 16px;
        margin-bottom: 15px;
        border-radius: 12px;
        border: 1.5px solid #d4cfc5;
        background: #faf7f2;
        transition: 0.2s ease-in-out;
        font-size: 0.95rem;
    }

    .signup-input:focus {
        outline: none;
        border-color: #9F9A7F;
        background: #fff;
    }

    .signup-btn {
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

    .signup-btn:hover {
        background: #8d876f;
    }

    .login-link {
        margin-top: 15px;
        font-size: 0.9rem;
    }

    .login-link a {
        color: #9F9A7F;
        text-decoration: none;
        font-weight: 600;
    }

    .login-link a:hover {
        text-decoration: underline;
    }
</style>
</head>

<body>

<div class="signup-container">
    <h2 class="signup-title">Create Your Account ✨</h2>
    <p class="signup-sub">Join Inspira and start discovering</p>

    <form method="POST">
        <input type="text" name="username" class="signup-input" placeholder="Username" required>
        <input type="email" name="email" class="signup-input" placeholder="Email" required>
        <input type="password" name="password" class="signup-input" placeholder="Password" required>
        
        <button type="submit" class="signup-btn">Sign Up</button>
    </form>

    <p class="login-link">
        Already have an account?
        <a href="login.php">Login</a>
    </p>
</div>

</body>
</html>


