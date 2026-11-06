<?php
require 'config/auth.php';
header('Location: ' . (isLoggedIn() ? 'dashboard.php' : 'login.php'));
exit;
