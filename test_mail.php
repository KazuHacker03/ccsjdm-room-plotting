<?php
require_once __DIR__ . "/vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug   = 2;
    $mail->Debugoutput = "html";
    $mail->isSMTP();
    $mail->Host       = "smtp.gmail.com";
    $mail->SMTPAuth   = true;
    $mail->Username   = "haroldcacho16@gmail.com";
    $mail->Password   = "PALITAN_NG_APP_PASSWORD";   // 16 characters, walang espasyo
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom("haroldcacho16@gmail.com", "CCSJDM System");
    $mail->addAddress("haroldcacho16@gmail.com");   // Ipadala sa sarili para sigurado
    $mail->Subject = "Test CCSJDM";
    $mail->Body    = "Test email.";

    $mail->send();
    echo "<h2>TAGUMPAY: Naipadala ang email.</h2>";
} catch (Exception $e) {
    echo "<h2>ERROR:</h2><pre>" . htmlspecialchars($mail->ErrorInfo) . "</pre>";
}