<?php
// Copy privately to config.call-local.php. Independent of all LiveKit settings.
return [
    'turn_urls' => [
        // All hosts must be this website's domain or its subdomains, served
        // by your own reachable server (not an overseas relay).
        // 'turn:turn.manaclinic.org:3478?transport=udp',
        // 'turn:turn.manaclinic.org:3478?transport=tcp',
        // 'turns:turn.manaclinic.org:5349?transport=tcp',
    ],
    'turn_secret' => '', // same random secret as coturn static-auth-secret
];
