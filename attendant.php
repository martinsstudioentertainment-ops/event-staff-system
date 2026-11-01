<?php
header("Location: /admin/attendant.php" . (isset($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] !== "" ? ("?" . $_SERVER["QUERY_STRING"]) : ""), true, 302);
exit;