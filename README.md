# PHPAfip
Clase conectora con los servicios de Afip.

Este software es de dominio público, bajo licencia "The Unlicense".
Esto significa que puede extenderlo a gusto y utilizarlo
en cualquier proyecto, sea comercial o no.
Para más información revise la licencia que acompaña este archivo, o
diríjase a https://unlicense.org/

Esta clase incluye los servicios más utilizados:
- Facturación electrónica A, B, C, E, T, etc...
- Consultas de padrón tributario.

Además se agregan 2 extras:
- Bonos fiscales
- Seguros de caución

Y la seguiré actualizando con el tiempo.
(También se aceptan contribuciones).

Para conocer más acerca de los servicios de Afip, diríjase a:
https://www.afip.gob.ar/ws/documentacion/

Para conocer las definiciones de más servicios, diríjase a:
https://www.afip.gob.ar/ws/documentacion/catalogo.asp

# Utilización

```php
<?php
include('afip.php');
$afip = new Afip(array(
    'homo' => true,
    'cuit' => 'XX-XXXXXXXX-X', // CUIT del cliente
    'dir_auth' => 'auth',      // Directorio para guardar los tokens (Debe existir)
    'dir_wsdl' => 'wsdl',      // Directorio para guardar los WSDL (Debe existir)
    'key_file' => 'path/to/rsa_key', // Llave privada rsa
    'key_pass' => ''                 // Contraseña llave rsa
    'crt_file' => 'path/to/cert.crt' // Certificado generado por Afip
    
));
$res = $afip->req('wsfe', 'FEParamGetTiposMonedas');

header('Content-Type: application/json');
echo json_encode($res, JSON_PRETTY_PRINT);
?>
```