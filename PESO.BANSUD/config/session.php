<?php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

function isAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

function isResident() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: unauthorized.php');
        exit();
    }
}

function requireResident() {
    requireLogin();
    if (!isResident()) {
        header('Location: unauthorized.php');
        exit();
    }
}

function logout() {
    session_destroy();
    header('Location: ../index.php');
    exit();
}
?>