<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'db/db_connect.php';
$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    if (empty($email)) {
        $error = "Email is required.";
    } else {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 1) {
            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));
            $stmt2 = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token=?, expires_at=?");
            $stmt2->bind_param("sssss", $email, $token, $expires, $token, $expires);
            $stmt2->execute();

            require 'vendor/autoload.php';

            function sendResetEmail($to, $resetLink) {
                $mail = new PHPMailer(true);
                try {
                    //Server settings
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'websmtp47@gmail.com'; // Updated Gmail address
                    $mail->Password   = 'jbvkukdacbphzaet';    // Updated Gmail App Password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    //Recipients
                    $mail->setFrom('websmtp47@gmail.com', 'Auntie Eddah POS');
                    $mail->addAddress($to);

                    //Content
                    $mail->isHTML(true);
                    $mail->Subject = 'Password Reset';
                    $mail->Body    = "Click the following link to reset your password: <a href='$resetLink'>$resetLink</a>";
                    $mail->AltBody = "Click the following link to reset your password: $resetLink";

                    $mail->send();
                    return true;
                } catch (Exception $e) {
                    // Optionally log $mail->ErrorInfo
                    return false;
                }
            }

            $reset_link = "http://localhost/edahhpos/reset_password.php?token=$token";
            if (sendResetEmail($email, $reset_link)) {
                $success = "Password reset link sent to your email.";
            } else {
                $error = "Failed to send reset email. Please try again later.";
            }
        } else {
            $error = "No active account found with that email.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Auntie Eddah POS - Forgot Password</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"/>
  <style>
    :root {
      --primary-color: #4a6baf;
      --primary-light: #5c7fc0;
      --secondary-color: #ff6b6b;
      --accent-color: #ffcc00;
      --dark-color: #003366;
      --light-color: #f0f4f8;
      --white: #ffffff;
      --text-color: #333333;
      --footer-bg: #222222;
      --footer-text: #cccccc;
      --success-color: #28a745;
      --error-color: #dc3545;
      --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.1);
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    body {
      background: linear-gradient(135deg, #003366 0%, #1a2a4f 100%);
      color: var(--text-color);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1.6;
      overflow-x: hidden;
      padding: 20px;
    }
    .forgot-container {
      display: flex;
      width: 100%;
      max-width: 1200px;
      min-height: 700px;
      background: var(--white);
      border-radius: 20px;
      box-shadow: 0 15px 50px rgba(0, 0, 0, 0.3);
      overflow: hidden;
      position: relative;
    }
    .forgot-banner {
      flex: 1;
      background: linear-gradient(135deg, rgba(0, 51, 102, 0.9), rgba(26, 42, 79, 0.9)), url('https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1740&q=80') center/cover no-repeat;
      color: var(--white);
      padding: 60px 40px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      position: relative;
      overflow: hidden;
    }
    .forgot-banner::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle at 10% 20%, rgba(255, 204, 0, 0.1) 0%, transparent 20%), radial-gradient(circle at 90% 80%, rgba(255, 107, 107, 0.1) 0%, transparent 20%);
    }
    .banner-content {
      position: relative;
      z-index: 2;
      max-width: 500px;
    }
    .logo {
      display: flex;
      align-items: center;
      gap: 15px;
      margin-bottom: 30px;
    }
    .logo img {
      height: 60px;
      transition: transform 0.3s ease;
    }
    .logo:hover img {
      transform: rotate(5deg);
    }
    .logo h1 {
      font-size: 2rem;
      font-weight: 800;
      background: linear-gradient(to right, var(--white), var(--accent-color));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }
    .banner-content h2 {
      font-size: 2.8rem;
      margin-bottom: 20px;
      line-height: 1.2;
    }
    .banner-content p {
      font-size: 1.1rem;
      margin-bottom: 30px;
      opacity: 0.9;
      line-height: 1.8;
    }
    .forgot-form-container {
      flex: 1;
      padding: 60px 50px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: var(--white);
    }
    .forgot-header {
      margin-bottom: 40px;
      text-align: center;
    }
    .forgot-header h2 {
      font-size: 2.2rem;
      color: var(--dark-color);
      margin-bottom: 10px;
    }
    .forgot-header p {
      color: var(--secondary-color);
      font-size: 1.1rem;
    }
    .forgot-form {
      width: 100%;
      max-width: 450px;
      margin: 0 auto;
    }
    .form-group {
      margin-bottom: 25px;
    }
    .form-group label {
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--dark-color);
      font-size: 1.1rem;
    }
    .input-group {
      position: relative;
    }
    .input-group input {
      width: 100%;
      padding: 16px 20px 16px 55px;
      border: 2px solid #e1e5eb;
      border-radius: 12px;
      font-size: 1.1rem;
      transition: var(--transition);
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }
    .input-group input:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 4px rgba(74, 107, 175, 0.2);
      outline: none;
    }
    .input-group i {
      position: absolute;
      left: 20px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--primary-color);
      font-size: 1.2rem;
    }
    .btn {
      display: block;
      width: 100%;
      padding: 18px;
      font-size: 1.2rem;
      font-weight: 700;
      border: none;
      border-radius: 12px;
      background: linear-gradient(to right, var(--primary-color), var(--primary-light));
      color: var(--white);
      text-decoration: none;
      transition: var(--transition);
      box-shadow: 0 8px 20px rgba(74, 107, 175, 0.4);
      cursor: pointer;
      margin: 30px 0 20px;
      position: relative;
      overflow: hidden;
    }
    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 0;
      height: 100%;
      background: rgba(255, 255, 255, 0.2);
      transition: var(--transition);
    }
    .btn:hover {
      transform: translateY(-5px);
      box-shadow: 0 12px 25px rgba(74, 107, 175, 0.5);
    }
    .btn:hover::before {
      width: 100%;
    }
    .btn i {
      margin-right: 10px;
    }
    .login-link {
      text-align: center;
      color: var(--text-color);
      font-size: 1.1rem;
      margin-top: 20px;
    }
    .login-link a {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 700;
      transition: var(--transition);
    }
    .login-link a:hover {
      color: var(--dark-color);
      text-decoration: underline;
    }
    .error-message {
      background: var(--error-color);
      color: var(--white);
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 25px;
      text-align: left;
      animation: fadeIn 0.5s ease;
    }
    .error-message p {
      margin-bottom: 5px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .error-message p:last-child {
      margin-bottom: 0;
    }
    .error-message i {
      font-size: 1.2rem;
      min-width: 25px;
    }
    .success-message {
      background: var(--success-color);
      color: var(--white);
      padding: 15px 20px;
      border-radius: 10px;
      margin-bottom: 25px;
      text-align: center;
      animation: fadeIn 0.5s ease;
    }
    .success-message p {
      margin: 0;
      font-weight: 500;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 992px) {
      .forgot-container {
        flex-direction: column;
        min-height: auto;
      }
      .forgot-banner {
        padding: 40px 30px;
      }
      .forgot-form-container {
        padding: 40px 30px;
      }
      .banner-content {
        max-width: 100%;
      }
    }
    @media (max-width: 768px) {
      .forgot-header h2 {
        font-size: 1.8rem;
      }
      .banner-content h2 {
        font-size: 2.2rem;
      }
    }
    @media (max-width: 576px) {
      .forgot-container {
        min-height: auto;
      }
      .forgot-banner, .forgot-form-container {
        padding: 30px 20px;
      }
    }
  </style>
</head>
<body>
  <div class="forgot-container">
    <div class="forgot-banner">
      <div class="banner-content">
        <div class="logo">
          <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='24' height='24'%3E%3Cpath fill='%23ffcc00' d='M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z'/%3E%3Cpath fill='%23ffffff' d='M12 18c3.31 0 6-2.69 6-6s-2.69-6-6-6-6 2.69-6 6 2.69 6 6 6zm-1-6.5v-3c0-.28.22-.5.5-.5s.5.22.5.5v3h1.5c.28 0 .5.22.5.5s-.22.5-.5.5h-4c-.28 0-.5-.22-.5-.5s.22-.5.5-.5H11z'/%3E%3C/svg%3E" alt="Logo">
          <h1>Auntie Eddah POS</h1>
        </div>
        <h2>Forgot Your Password?</h2>
        <p>Enter your email address and we'll send you a link to reset your password.</p>
      </div>
    </div>
    <div class="forgot-form-container">
      <div class="forgot-header">
        <h2>Reset Password</h2>
        <p>We'll send a reset link to your email</p>
      </div>
      <?php if ($success): ?>
        <div class="success-message">
          <p><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></p>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error-message">
          <p><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></p>
        </div>
      <?php endif; ?>
      <form class="forgot-form" method="POST">
        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-group">
            <i class="fas fa-envelope"></i>
            <input type="email" id="email" name="email" placeholder="Enter your email" required>
          </div>
        </div>
        <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
      </form>
      <div class="login-link">
        <p>Remembered your password? <a href="login.php">Login here</a></p>
      </div>
    </div>
  </div>
  <script>
    // Add animation to button on hover
    const forgotBtn = document.querySelector('.btn');
    forgotBtn.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-5px)';
    });
    forgotBtn.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0)';
    });
    // Add error animation if there are errors
    if (document.querySelector('.error-message')) {
      const errorMessage = document.querySelector('.error-message');
      errorMessage.style.animation = 'fadeIn 0.5s ease';
    }
    // Add success animation if there is a success message
    if (document.querySelector('.success-message')) {
      const successMessage = document.querySelector('.success-message');
      successMessage.style.animation = 'fadeIn 0.5s ease';
    }
  </script>
</body>
</html>