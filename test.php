<?php
$start = microtime(true);
$pdo = new PDO("mysql:dbname=crm_logs;host=127.0.0.1;port=3386", 'root', '123456');
$stmt = $pdo->query("SELECT * FROM logs LIMIT 1");
$data = $stmt->fetch(PDO::FETCH_ASSOC);
var_dump(json_encode($data));
$stmt = $pdo->prepare("UPDATE logs SET valueId=? WHERE lid=?");
var_dump($stmt->execute([mt_rand(0, 1000), 1]));
$stmt = $pdo->query("SELECT * FROM logs LIMIT 1");
$data = $stmt->fetch(PDO::FETCH_ASSOC);
var_dump(json_encode($data));
echo sprintf("%0.4f ms", (microtime(true) - $start) * 1000);