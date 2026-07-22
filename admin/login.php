<?php
declare(strict_types=1);
$query=isset($_GET['reset'])?'?reset=1':'';
header('Location: ../login.php'.$query);exit;
