<?php

return [
    'name' => 'Proveedores no verificados',
    'verify' => 'Verificar proveedor ":name"',
    'forms' => [
        'email' => 'Correo electrónico',
        'store_name' => 'Nombre de la tienda',
        'store_phone' => 'Teléfono de la tienda',
        'vendor_phone' => 'Teléfono',
        'verify_vendor' => 'Verificar proveedor',
        'registered_at' => 'Registrado en',
        'certificate' => 'Certificado',
        'government_id' => 'Identificación gubernamental',
    ],
    'view_certificate' => 'Ver certificado',
    'view_government_id' => 'Ver documento de identidad',
    'approve' => 'Aprobar',
    'reject' => 'Rechazar',
    'approve_vendor_confirmation' => 'Aprobar la confirmación del proveedor',
    'approve_vendor_confirmation_description' => '¿Está seguro de que realmente desea aprobar a :vendor para vender en este sitio?',
    'reject_vendor_confirmation' => 'Confirmación de rechazo del vendedor',
    'reject_vendor_confirmation_description' => '¿Está seguro de que realmente desea rechazar a :vendor para vender en este sitio?',
    'new_vendor_notifications' => [
        'new_vendor' => 'Nuevo vendedor',
        'view' => 'Vista',
        'description' => ':customer se ha registrado pero no está verificado.',
    ],
];
