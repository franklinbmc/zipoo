<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/billing_lib.php';

    ensure_billing_tables($pdo);
    seed_billing_for_existing_businesses($pdo);

    $count = (int) $pdo->query('SELECT COUNT(*) FROM tbl_sms_bundles')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO tbl_sms_bundles (name, sms_count, price, status) VALUES (:name, :sms_count, :price, "active")');
        foreach ([
            ['Starter SMS', 500, 15000],
            ['Growth SMS', 2000, 50000],
            ['Business SMS', 5000, 110000],
        ] as $bundle) {
            $stmt->execute([':name' => $bundle[0], ':sms_count' => $bundle[1], ':price' => $bundle[2]]);
        }
    }

    $settings = [
        ['lipa_payment_enabled', '1'],
        ['lipa_numbers', '[]'],
        ['lipa_auto_approve', '1'],
        ['lipa_auto_approve_max', '0'],
        ['lipa_allowed_senders', 'M-PESA,MPESA,MIXX BY YAS,MIXXBYYAS,TIGOPESA,AirtelMoney,AIRTEL,HaloPesa'],
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_saas_settings (setting_group, setting_key, setting_value)
         VALUES ("lipa", :setting_key, :setting_value)
         ON DUPLICATE KEY UPDATE setting_value = setting_value'
    );
    foreach ($settings as [$key, $value]) {
        $stmt->execute([':setting_key' => $key, ':setting_value' => $value]);
    }
};
