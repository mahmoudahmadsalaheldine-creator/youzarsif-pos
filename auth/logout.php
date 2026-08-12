<?php
session_start();
require_once "../config/app.php";
session_destroy();
header("Location: " . BASE_URL . "auth/login.php");
exit;
