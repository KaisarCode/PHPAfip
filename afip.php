<?php
/**
# PHPAfip - 2019 KaisarCode.com
Clase conectora con los servicios de Afip.

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

Este software es de dominio público, bajo licencia "The Unlicense".
Esto significa que puede extenderlo a gusto y utilizarlo
en cualquier proyecto, sea comercial o no.
Para más información revise la licencia que acompaña este archivo, o
diríjase a https://unlicense.org/
*/
class Afip
{
    private $url;
    private $dir;
    private $wsdl;
    
    function __construct($opts = array())
    {
        ini_set("soap.wsdl_cache_enabled", "0");
        
        // Opciones
        $opts = (object) $opts;
        if (!isset($opts->homo)) $opts->homo = true;
        if (!isset($opts->cuit)) $opts->cuit = '';
        if (!isset($opts->dir)) $opts->dir = '';
        if (!isset($opts->wsdl)) $opts->wsdl = '';
        if (!isset($opts->key_file)) $opts->key_file = 'key';
        if (!isset($opts->crt_file)) $opts->crt_file = 'crt';
        if (!isset($opts->crt_pass)) $opts->crt_pass = '';
        
        $this->homo = (boolean) $opts->homo;
        $this->dir = realpath($opts->dir);
        $this->wsdl = realpath($opts->wsdl);
        $this->key_file = $opts->key_file;
        $this->crt_file = $opts->crt_file;
        $this->crt_pass = $opts->crt_pass;
        $this->cuit = $opts->cuit;
        
        // URLs
        $this->url = new stdClass();
        $this->url->wsaa = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
        $this->url->wsfe = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'; // A, B, C Sin detalle
        $this->url->wsmtxca = 'https://serviciosjava.afip.gob.ar/wsmtxca/services/MTXCAService'; // A, B Con detalle
        $this->url->wsfex = 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx'; // E (Exportación)
        $this->url->wsct = 'https://serviciosjava.afip.gob.ar/wsct/CTService'; // T (Turismo)
        $this->url->padron_a5 = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5'; // Consulta padrón tributario (Datos de la persona)
        
        $this->url->wsbfev = 'https://servicios1.afip.gov.ar/wsbfev1/service.asmx'; // Bonos fiscales
        $this->url->wsseg = 'https://servicios1.afip.gov.ar/wsseg/service.asmx'; // Seguros de caución
        
        // URLs homologación
        if ($this->homo) {
            $this->url->wsaa = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
            $this->url->wsfe = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
            $this->url->wsmtxca = 'https://fwshomo.afip.gov.ar/wsmtxca/services/MTXCAService';
            $this->url->wsfex = 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx';
            $this->url->wsct = 'https://fwshomo.afip.gov.ar/wsct/CTService';
            $this->url->padron_a5 = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5';
            
            $this->url->wsbfev = 'https://wswhomo.afip.gov.ar/wsbfev1/service.asmx';
            $this->url->wsseg = 'https://wswhomo.afip.gov.ar/wsseg/service.asmx';
        }
        
        return $this;
    }
    
    // CARGA WSDL //////////////////////////////////////////////////////
    private function getWSDL($serv) {
        $wspfx = '';
        if ($this->homo) $wspfx = '-homo';
        $wsdl_file = $this->wsdl."/$serv"."$wspfx.wsdl";
        if (!file_exists($wsdl_file)) {
            $url = $this->url->{$serv}."?wsdl";
            $wsdl = file_get_contents($url);
            file_put_contents($wsdl_file, $wsdl);
        }
        return $wsdl_file;
    }
    
    // AUTENTIFICACIÓN /////////////////////////////////////////////////
    public function auth($serv)
    {
        $cuit = $this->cuit;
        
        $wspfx = '';
        if ($this->homo) $wspfx = '-homo';
        $ta_file  = $this->dir."/ta-$serv$wspfx.xml";
        $tra_file = $this->dir."/tra-$serv$wspfx.xml";
        $tra_temp = $this->dir."/tra-$serv$wspfx.tmp";
        
        $ta = null;
        if (file_exists($ta_file)) {
            $ta = file_get_contents($ta_file);
            $ta = new SimpleXMLElement($ta);
            $date = date('c',date('U')-600);
            if (
            isset($ta->header) &&
            isset($ta->header->expirationTime)
            ) {
                $exp = $ta->header->expirationTime;
                if ($exp < $date) $ta = null;
            }
        }
        
        if (!$ta) {
            $tra = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">'.
            '</loginTicketRequest>');
            $tra->addChild('header');
            $tra->header->addChild('uniqueId', date('U'));
            $tra->header->addChild('generationTime', date('c',date('U')-600));
            $tra->header->addChild('expirationTime', date('c',date('U')+600));
            $tra->addChild('service', $serv);
            $tra->asXML($tra_file);
            $sign = openssl_pkcs7_sign($tra_file, $tra_temp, "file://".$this->crt_file,
                array("file://".$this->key_file, $this->crt_pass),
                array(), !PKCS7_DETACHED
            );
            if ($sign) {
                $tmp = fopen($tra_temp, "r");
                $i = 0; $cms = "";
                while (!feof($tmp)) {
                    $buffer = fgets($tmp);
                    if ($i++ >= 4) { $cms .= $buffer; }
                }
                fclose($tmp);
                @unlink($tra_file);
                @unlink($tra_temp);
                
                $wsdl_file = $this->getWSDL('wsaa');
                $url =  $this->url->wsaa;
                $soap = new SoapClient($wsdl_file, array(
                    'soap_version'   => SOAP_1_2,
                    'location'       => $url,
                    'trace'          => 0,
                    'exceptions'     => 0
                ));
                
                $res = @$soap->loginCms(array('in0' => $cms));
                
                if ($res) {
                    if (!isset($res->faultcode)) {
                        $ta = $res->loginCmsReturn;
                        file_put_contents($ta_file, $ta);
                        $ta = new SimpleXMLElement($ta);
                    } else {
                        $ta = new stdClass();
                        $ta->Err = array( (object) array(
                            'Code' => $res->faultcode,
                            'Msg' => $res->faultstring
                        ));
                    }
                } else {
                    $ta = new stdClass();
                    $ta->Err = array( (object) array(
                        'Code' => 'afip_unexpected_error_wsaa',
                        'Msg' => 'Error inesperado en los servidores de Afip.'
                    ));
                }
                
            } else {
                $ta = new stdClass();
                $ta->Err = array( (object) array(
                    'Code' => 'tra_sign_err',
                    'Msg' => 'No fue posible firmar el ticket de acceso.'
                ));
            }
        }
        
        return $ta;
    }
    
    // CONSULTAR SERVICIOS /////////////////////////////////////////////
    public function request($serv, $op, $params = null)
    {
        $out = null;
        $ta = $this->auth($serv);
        if ($ta) {
            if (!isset($ta->Err)) {
                if (isset($ta->credentials)) {
                    
                    $creds = $ta->credentials;
                    
                    if (!$params) $params = array();
                    $params = (array) $params;
                    if (
                    $serv == 'wsfe' ||
                    $serv == 'wsfex' ||
                    $serv == 'wsseg' ||
                    $serv == 'wsbfev'
                    ) {
                        if (!isset($params['Auth'])) {
                            $params['Auth'] = array();
                        }
                        if (!isset($params['Auth']['Token'])) {
                            $params['Auth']['Token'] = $creds->token;
                        }
                        if (!isset($params['Auth']['Sign'])) {
                            $params['Auth']['Sign'] = $creds->sign;
                        }
                        if (!isset($params['Auth']['Cuit'])) {
                            $params['Auth']['Cuit'] = $this->cuit;
                        }
                    }
                    if (
                    $serv == 'wsmtxca' ||
                    $serv == 'wsct'
                    ) {
                        if (!isset($params['authRequest'])) {
                            $params['authRequest'] = array();
                        }
                        if (!isset($params['authRequest']['token'])) {
                            $params['authRequest']['token'] = $creds->token;
                        }
                        if (!isset($params['authRequest']['sign'])) {
                            $params['authRequest']['sign'] = $creds->sign;
                        }
                        if (!isset($params['authRequest']['cuitRepresentada'])) {
                            $params['authRequest']['cuitRepresentada'] = $this->cuit;
                        }
                    }
                    if (
                    $serv == 'padron_a5'
                    ) {
                        if (!isset($params['token'])) {
                            $params['token'] = $creds->token;
                        }
                        if (!isset($params['sign'])) {
                            $params['sign'] = $creds->sign;
                        }
                        if (!isset($params['sign'])) {
                            $params['cuitRepresentada'] = $this->cuit;
                        }
                    }
                    
                    $wsdl_file = $this->getWSDL($serv);
                    $soap = new SoapClient($wsdl_file, array(
                        'soap_version'   => SOAP_1_2,
                        'trace'          => 0,
                        'exceptions'     => 0
                    ));
                    $res = $soap->$op($params);
                    
                    if ($res) {
                        if (!isset($res->faultcode)) {
                            $out = $res;
                        } else {
                            $out = new stdClass();
                            $out->Err = array( (object) array(
                                'Code' => $res->faultcode,
                                'Msg' => $res->faultstring
                            ));
                        }
                    } else {
                        $out = new stdClass();
                        $out->Err = array( (object) array(
                            'Code' => 'afip_unexpected_error',
                            'Msg' => 'Error inesperado en los servidores de Afip.'
                        ));
                    }
                } else {
                    $out = new stdClass();
                    $out->Err = array( (object) array(
                        'Code' => 'afip_unexpected_error',
                        'Msg' => 'Error inesperado en los servidores de Afip.'
                    ));
                }
            } else {
                $out = $ta;
            }
        } else {
            $out = new stdClass();
            $out->Err = array( (object) array(
                'Code' => 'unexpected_error',
                'Msg' => 'Error inesperado.'
            ));
        }
        
        return $out;
    }
}
