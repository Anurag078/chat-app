<?php
session_start();
require_once "../includes/db.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION["user_id"];
    $message = htmlspecialchars($_POST["message"]);

    $stmt = $pdo->prepare("INSERT INTO messages (user_id, message_text) VALUES (?, ?)");
    $stmt->execute([$user_id, $message]);

    echo "Saved";
}
?>
