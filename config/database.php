<?php

$host     = 'localhost';
$user     = 'root';
$password = '';              
$database = 'alumniconnect';

$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    die('Koneksi database gagal: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Jakarta');