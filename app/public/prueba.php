<?php
session_start();
$_SESSION['logged_in'] = true;
$_SESSION['empresa_db'] = 'grupoide_TU_BASE_DE_EMPRESA';
$_SESSION['usuario_nombre'] = 'Prueba';
header('Location: /ventas');