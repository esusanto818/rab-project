<?php
if (!isset($_SESSION)) {
    if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

// 1. Amankan penangkapan parameter POST dengan operator ??
$searchField = $_POST["query"] ?? null;
$offset      = isset($_POST["start"]) ? intval($_POST["start"]) : 0;
$limit       = isset($_POST["limit"]) ? intval($_POST["limit"]) : 250;

// 2. Amankan proses json2Array
$rawItemInfo = $_POST["iteminfo"] ?? '';
$dataArray   = !empty($rawItemInfo) ? json2Array($rawItemInfo) : [];

// 3. Tangani pembentukan string kondisi aman berbasis array
if (empty($dataArray)) {
    $CheckItemNum  = "[0]";
    $CheckItemChar = "['0']";
} else {
    $formattedData = is_array($dataArray) ? implode(",", $dataArray) : $dataArray;
    $CheckItemNum  = "[" . $formattedData . "]";
    $CheckItemChar = "['" . $formattedData . "']";
}

$db = new crud();
$task = $_POST['task'] ?? 'read';

// Otorisasi backend mandiri: login wajib dan hak operasi mengikuti FormAccess menu.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
$requiredAccess = 1;
if (in_array($task, ['create', 'add'], true)) $requiredAccess = 2;
if (in_array($task, ['update', 'edit'], true)) $requiredAccess = 3;
if ($task === 'delete') $requiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $requiredAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi master ini.']);
    exit;
}

switch ($task) {

    // ==========================================
    // 1. CREATE (Tambah Customer Lengkap)
    // ==========================================
    case ("create"):
    case ("add"):
        $queryC = '';

        // Master Customer
        $table = "m_customer";
        $data = array(
            "name"   => checksinglequote($_POST["name"] ?? ''),
            "init"   => checksinglequote($_POST["init"] ?? ''),
            "remark" => checksinglequote($_POST["remark"] ?? ''),
            "active" => 'true'
        );
        $queryC .= insertQ($table, $data) . ";";

        // Alamat Customer Utama
        $table = "m_address";
        $isCustType = isset($_POST["cust_type"]) ? ($_POST["cust_type"] === true || $_POST["cust_type"] === 'true' || $_POST["cust_type"] === '1') : false;
        $countryId  = $isCustType ? 1 : intval($_POST["country"] ?? 1);

        $data = array(
            "customer_id" => "currval('m_customer_id_seq'::regclass)",
            "active"      => 'true',
            "address"     => checksinglequote($_POST["address"] ?? ''),
            "country_id"  => $countryId
        );
        $queryC .= insertQ($table, $data) . ";";

        // Data NPWP jika WNI (cust_type true)
        if ($isCustType) {
            $table = "customer_npwp";
            $data = array(
                "npwp_no"        => checksinglequote($_POST["npwp"] ?? ''),
                "active"         => 'true',
                "customer_id"    => "currval('m_customer_id_seq'::regclass)",
                "address_office" => !empty($_POST["ass_addr_off"]) ? checksinglequote($_POST["ass_addr_off"]) : null
            );
            $queryC .= insertQ($table, $data) . ";";

            // Alamat NPWP
            $table = "m_address";
            $data = array(
                "npwp_id"    => "currval('customer_npwp_id_seq'::regclass)",
                "active"     => 'true',
                "street"     => checksinglequote($_POST["street"] ?? ''),
                "block"      => checksinglequote($_POST["block"] ?? ''),
                "street_no"  => checksinglequote($_POST["street_no"] ?? ''),
                "rt"         => checksinglequote($_POST["rt"] ?? ''),
                "rw"         => checksinglequote($_POST["rw"] ?? ''),
                "village_id" => (empty($_POST["village"]) || $_POST["village"] == 0) ? null : intval($_POST["village"]),
                "country_id" => 1
            );
            $queryC .= insertQ($table, $data) . ";";
        }

        // Limit Kredit
        $limitCurrency = intval($_POST["limit_currency"] ?? 0);
        $limitAmount   = floatval(str_replace(",", "", $_POST["limit_amount"] ?? 0));
        if ($limitCurrency > 0 && $limitAmount > 0) {
            $table = "customer_credit_limit";
            $data = array(
                "customer_id"  => "currval('m_customer_id_seq'::regclass)",
                "limit_amount" => $limitAmount,
                "currency_id"  => $limitCurrency,
                "from_date"    => "now()",
                "to_date"      => null
            );
            $queryC .= insertQ($table, $data) . ";";
        }

        // Periode Pembayaran
        $paymentPeriod = floatval(str_replace(",", "", $_POST["payment_period"] ?? 0));
        if ($paymentPeriod > 0) {
            $table = "customer_payment_period";
            $data = array(
                "customer_id" => "currval('m_customer_id_seq'::regclass)",
                "period"      => $paymentPeriod,
                "from_date"   => "now()",
                "to_date"     => null
            );
            $queryC .= insertQ($table, $data) . ";";
        }

        // Kontak Kantor (Email, Mobile, Phone, Fax)
        $contactsConfig = [
            "office_email"  => 1,
            "office_mobile" => 2,
            "office_phone"  => 3,
            "office_fax"    => 4
        ];

        foreach ($contactsConfig as $postKey => $contactType) {
            if (!empty($_POST[$postKey])) {
                $table = "m_contact_no";
                $data = array(
                    "customer_id"       => "currval('m_customer_id_seq'::regclass)",
                    "supplier_id"       => null,
                    "contact_person_id" => null,
                    "contact_type"      => $contactType,
                    "contact_no"        => checksinglequote($_POST[$postKey])
                );
                $queryC .= insertQ($table, $data) . ";";
            }
        }

        // Array Kontak Person Tambahan
        $rawContacts  = $_POST["arrContact"] ?? '[]';
        $dataContacts = !empty($rawContacts) ? json_decode($rawContacts) : [];

        if (is_array($dataContacts)) {
            foreach ($dataContacts as $contact) {
                if (!empty($contact->name)) {
                    $table = "m_contact_person";
                    $data = array(
                        "customer_id" => "currval('m_customer_id_seq'::regclass)",
                        "supplier_id" => null,
                        "name"        => checksinglequote($contact->name ?? ''),
                        "position"    => checksinglequote($contact->position ?? ''),
                        "remark"      => checksinglequote($contact->remark ?? '')
                    );
                    $queryC .= insertQ($table, $data) . ";";

                    if (!empty($contact->address)) {
                        $table = "m_address";
                        $data = array(
                            "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                            "active"            => 'true',
                            "address"           => checksinglequote($contact->address)
                        );
                        $queryC .= insertQ($table, $data) . ";";
                    }

                    $subContactsMap = [
                        "email"  => 1,
                        "mobile" => 2,
                        "phone"  => 3
                    ];

                    foreach ($subContactsMap as $sKey => $sVal) {
                        if (!empty($contact->$sKey)) {
                            $table = "m_contact_no";
                            $data = array(
                                "contact_type"      => $sVal,
                                "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                                "contact_no"        => checksinglequote($contact->$sKey)
                            );
                            $queryC .= insertQ($table, $data) . ";";
                        }
                    }
                }
            }
        }

        $db->cmdQ($queryC);
        echo $db->getResult();
    break;

    // ==========================================
    // 2. READ (Daftar Customer)
    // ==========================================
    case ("read"):
        $queryParam = $_POST["query"] ?? '';
        $sResult    = searchData($queryParam, array("c.name", "c.init", "a.address"), $limit);
        $searchSQL  = is_array($sResult["searchSQL"]) ? '' : strval($sResult["searchSQL"]);

        $fields = "
            c.id, cn.id country, c.init, c.name, coalesce(c.remark,'') remark,
            replace(coalesce(a.address,''),'\n','<br>') address, n.npwp_no npwp,
            anp.street, anp.block, anp.street_no, anp.rt, anp.rw, anp.village_id, anp.address npwp_addr, anp.country_id,
            l.limit_amount, l.currency_id, cr.init || '' || to_char(l.limit_amount, '999,999,999,999.99') cr_limit,
            cnt.contact_name,
            case when lower(cn.name)='indonesia' then 1 else 2 end cst_type
        ";

        $table = "
            m_customer c
            left join m_address a on a.customer_id=c.id and a.active is true
            left join m_country cn on cn.id=a.country_id
            left join customer_npwp n on n.customer_id=c.id and n.active is true
            left join m_address anp on anp.npwp_id=n.id and anp.active is true
            left join customer_credit_limit l on l.customer_id=c.id and l.to_date is null
            left join m_currency cr on cr.id=l.currency_id
            left join (
                select customer_id, array_to_string(array_agg(name || ' (' || position || ')'), '<br>') contact_name
                from m_contact_person
                where customer_id > 0
                group by customer_id
            ) cnt on coalesce(cnt.customer_id, 0) = coalesce(c.id, 0)
        ";

        $where = "c.active is true " . $searchSQL;
        $order = "c.name ASC";

        $db->select($table, $fields, $where, $order, $limit, $offset);
        echo $db->getResult();
    break;

    // ==========================================
    // 3. READ EDIT (Ambil Data Detail Customer)
    // ==========================================
    case ("readedit"):
    case ("read_edit"):
        $idCustomer = intval($_POST['id'] ?? 0);

        $fields = "
            c.id, cn.id country, c.init, c.name, coalesce(c.remark,'') remark, a.id address_id, a.address,
            n.id npwp_id, n.npwp_no npwp, n.address_office as_addr, anp.id npwp_address_id,
            anp.street, anp.block, anp.street_no, anp.rt, anp.rw, anp.address npwp_addr, anp.country_id,
            anp.village_id village, nv.district, nv.city, nv.province, nv.postalcode postal_code,
            l.id customer_credit_limit_id, l.limit_amount, l.currency_id limit_currency,
            p.id payment_period_id, p.period payment_period,
            cnt.office_email_id, cnt.office_email, cnt.office_mobile_id, cnt.office_mobile,
            cnt.office_phone_id, cnt.office_phone, cnt.office_fax_id, cnt.office_fax,
            case when lower(cn.name)='indonesia' then 1 else 2 end cst_type
        ";

        $table = "
            m_customer c
            left join m_address a on a.customer_id=c.id and a.active is true
            left join m_country cn on cn.id=a.country_id
            left join customer_npwp n on n.customer_id=c.id and n.active is true
            left join m_address anp on anp.npwp_id=n.id and anp.active is true
            left join customer_credit_limit l on l.customer_id=c.id and l.to_date is null
            left join customer_payment_period p on p.customer_id=c.id and p.to_date is null
            left join m_currency cr on cr.id=l.currency_id
            left join (
                select customer_id,
                max(case when contact_type = 1 then id end) as office_email_id,
                max(case when contact_type = 1 then contact_no end) as office_email,
                max(case when contact_type = 2 then id end) as office_mobile_id,
                max(case when contact_type = 2 then contact_no end) as office_mobile,
                max(case when contact_type = 3 then id end) as office_phone_id,
                max(case when contact_type = 3 then contact_no end) as office_phone,
                max(case when contact_type = 4 then id end) as office_fax_id,
                max(case when contact_type = 4 then contact_no end) as office_fax
                from m_contact_no
                where customer_id > 0
                group by customer_id
            ) cnt on coalesce(cnt.customer_id, 0) = coalesce(c.id, 0)
            left join (
                select v.id village, v.postalcode, d.id district, c.id city, p.id province
                from m_village v
                left join m_district d on d.id=v.district_id
                left join m_city c on c.id=d.city_id
                left join m_province p on p.id=c.province_id
            ) nv on nv.village = anp.village_id
        ";

        $where = "c.id = " . $idCustomer;

        $db->select($table, $fields, $where);
        echo $db->getResult();
    break;

    // ==========================================
    // 4. WILAYAH & LOOKUP (Country, Prov, City, etc)
    // ==========================================
    case ("readcountry"):
        $db->select("m_country", "id, name", "1=1", "name ASC");
        echo $db->getResult();
    break;

    case ("readprov"):
        $countryId = intval($_POST['country_id'] ?? 0);
        $db->select("m_province", "id, name", "country_id = " . $countryId, "name ASC");
        echo $db->getResult();
    break;

    case ("readcity"):
        $provinceId = intval($_POST['province_id'] ?? 0);
        $db->select("m_city", "id, name", "province_id = " . $provinceId, "name ASC");
        echo $db->getResult();
    break;

    case ("readdistrict"):
        $cityId = intval($_POST['city_id'] ?? 0);
        $db->select("m_district", "id, name", "city_id = " . $cityId, "name ASC");
        echo $db->getResult();
    break;

    case ("readvillage"):
        $districtId = intval($_POST['district_id'] ?? 0);
        $db->select("m_village", "id, name, postalcode", "district_id = " . $districtId, "name ASC");
        echo $db->getResult();
    break;

    case ('readcontact'):
        $customerId = intval($_POST['id'] ?? 0);
        $fields = "
            p.id cont_id, p.customer_id, p.name, p.position, coalesce(p.remark,'') remark, a.id cont_address_id, a.address,
            cnt.cont_email_id, cnt.email, cnt.cont_mobile_id, cnt.mobile, cnt.cont_phone_id, cnt.phone, cdt.contact
        ";
        $table = "
            m_contact_person p
            left join m_address a on a.contact_person_id = p.id and a.active is true
            left join (
                select contact_person_id,
                    max(case when contact_type = 1 then id end) as cont_email_id,
                    max(case when contact_type = 1 then contact_no end) as email,
                    max(case when contact_type = 2 then id end) as cont_mobile_id,
                    max(case when contact_type = 2 then contact_no end) as mobile,
                    max(case when contact_type = 3 then id end) as cont_phone_id,
                    max(case when contact_type = 3 then contact_no end) as phone
                from m_contact_no
                where contact_person_id > 0
                group by contact_person_id
            ) cnt on coalesce(cnt.contact_person_id, 0) = coalesce(p.id, 0)
            left join (
                select contact_person_id, array_to_string(array_agg(
                    case
                        when contact_type=1 then 'Email'
                        when contact_type=2 then 'Mobile'
                        when contact_type=3 then 'Phone'
                        when contact_type=4 then 'Fax'
                    end
                    || ' : ' || contact_no), '<br>') contact
                from m_contact_no
                where contact_person_id > 0
                group by contact_person_id
            ) cdt on coalesce(cdt.contact_person_id, 0) = coalesce(p.id, 0)
        ";
        $where = "p.customer_id = " . $customerId;
        $order = "p.name ASC";

        $db->select($table, $fields, $where, $order);
        echo $db->getResult();
    break;

    // ==========================================
    // 5. HISTORY DATA LOG
    // ==========================================
    case ('HistoryData'):
        $txId   = intval($_POST['id'] ?? 0);
        $fields = "
            tx_id, date, count,
            case amend_name
                when 'init' then '[EDIT] - Init'
                when 'name' then '[EDIT] - Name'
                when 'description' then '[EDIT] - Description'
                when 'remark' then '[EDIT] - Remark'
                else amend_name
            end ::character varying(200) AS amend_name,
            before_amendment, after_amendment, user_name, user_time
        ";
        $where   = "tx_id::int = " . $txId . " and table_name = 'm_customer'";
        $orderBy = "count desc";

        $db->select('history', "view_single_table_log", $fields, $where, $orderBy);
        echo $db->getResult();
    break;

    // ==========================================
    // 6. UPDATE (Edit Customer)
    // ==========================================
    case ('update'):
    case ('edit'):
        $queryC = '';
        $customerId = intval($_POST["id"] ?? 0);
        $rawContacts = $_POST["arrContact"] ?? '[]';
        $dataContacts = !empty($rawContacts) ? json_decode($rawContacts) : [];

        if ($customerId <= 0) {
            echo json_encode(["success" => "false", "status" => "Error", "message" => "ID Customer tidak valid."]);
            break;
        }

        // Cek kontak person lama yang perlu dihapus
        $checkDb = new crud();
        $checkDb->select("m_contact_person", "id, address_id", "customer_id = " . $customerId, "id ASC");
        $resCheckJSON = $checkDb->getResult();
        $resCheckArr  = json_decode($resCheckJSON, true);
        $dataCheck    = $resCheckArr["data"] ?? [];

        foreach ($dataCheck as $rowOld) {
            $dataFound = false;
            $cId    = $rowOld['id'];
            $addrId = $rowOld['address_id'] ?? 0;

            if (is_array($dataContacts)) {
                foreach ($dataContacts as $contact) {
                    if (isset($contact->cont_id) && $contact->cont_id == $cId) {
                        $dataFound = true;
                        break;
                    }
                }
            }

            if (!$dataFound) {
                if ($addrId > 0) {
                    $queryC .= deleteQ("m_address", "id = " . intval($addrId)) . ";";
                }
                $queryC .= deleteQ("m_contact_no", "contact_person_id = " . intval($cId)) . ";";
                $queryC .= deleteQ("m_contact_person", "id = " . intval($cId)) . ";";
            }
        }

        // Update Master Customer
        $table = "m_customer";
        $data = array(
            "name"   => checksinglequote($_POST["name"] ?? ''),
            "init"   => checksinglequote($_POST["init"] ?? ''),
            "remark" => checksinglequote($_POST["remark"] ?? '')
        );
        $where = "id = " . $customerId;
        $queryC .= updateQ($table, $data, $where) . ";";

        // Update Alamat Customer
        $addressId  = intval($_POST["address_id"] ?? 0);
        $newAddress = checksinglequote($_POST["address"] ?? '');
        $custType   = $_POST["cust_type"] ?? false;
        $isCustType = ($custType === true || $custType === 'true' || $custType === '1');
        $countryId  = $isCustType ? 1 : intval($_POST["country"] ?? 1);

        if ($addressId > 0) {
            $queryC .= updateQ("m_address", array(
                "address"    => $newAddress,
                "country_id" => $countryId
            ), "id = " . $addressId) . ";";
        } else if (!empty($newAddress)) {
            $queryC .= insertQ("m_address", array(
                "customer_id" => $customerId,
                "active"      => 'true',
                "address"     => $newAddress,
                "country_id"  => $countryId
            )) . ";";
        }

        // Update NPWP jika WNI
        if ($isCustType) {
            $npwpId  = intval($_POST["npwp_id"] ?? 0);
            $npwpNo  = checksinglequote($_POST["npwp"] ?? '');
            $asOffice = !empty($_POST["ass_addr_off"]) ? checksinglequote($_POST["ass_addr_off"]) : null;

            if ($npwpId > 0) {
                $queryC .= updateQ("customer_npwp", array(
                    "npwp_no"        => $npwpNo,
                    "address_office" => $asOffice
                ), "id = " . $npwpId) . ";";

                $npwpAddrId = intval($_POST["npwp_address_id"] ?? 0);
                if ($npwpAddrId > 0) {
                    $queryC .= updateQ("m_address", array(
                        "street"     => checksinglequote($_POST["street"] ?? ''),
                        "block"      => checksinglequote($_POST["block"] ?? ''),
                        "street_no"  => checksinglequote($_POST["street_no"] ?? ''),
                        "rt"         => checksinglequote($_POST["rt"] ?? ''),
                        "rw"         => checksinglequote($_POST["rw"] ?? ''),
                        "village_id" => (empty($_POST["village"]) || $_POST["village"] == 0) ? null : intval($_POST["village"])
                    ), "id = " . $npwpAddrId) . ";";
                }
            } else if (!empty($npwpNo)) {
                $queryC .= insertQ("customer_npwp", array(
                    "npwp_no"        => $npwpNo,
                    "active"         => 'true',
                    "customer_id"    => $customerId,
                    "address_office" => $asOffice
                )) . ";";

                $queryC .= insertQ("m_address", array(
                    "npwp_id"    => "currval('customer_npwp_id_seq'::regclass)",
                    "active"     => 'true',
                    "street"     => checksinglequote($_POST["street"] ?? ''),
                    "block"      => checksinglequote($_POST["block"] ?? ''),
                    "street_no"  => checksinglequote($_POST["street_no"] ?? ''),
                    "rt"         => checksinglequote($_POST["rt"] ?? ''),
                    "rw"         => checksinglequote($_POST["rw"] ?? ''),
                    "village_id" => (empty($_POST["village"]) || $_POST["village"] == 0) ? null : intval($_POST["village"]),
                    "country_id" => 1
                )) . ";";
            }
        }

        // Limit Kredit
        $limitCurrency = intval($_POST["limit_currency"] ?? 0);
        $limitAmount   = floatval(str_replace(",", "", $_POST["limit_amount"] ?? 0));
        $creditLimitId = intval($_POST["customer_credit_limit_id"] ?? 0);

        if ($limitCurrency > 0 && $limitAmount > 0) {
            if ($creditLimitId > 0) {
                $queryC .= updateQ("customer_credit_limit", array(
                    "limit_amount" => $limitAmount,
                    "currency_id"  => $limitCurrency
                ), "id = " . $creditLimitId) . ";";
            } else {
                $queryC .= insertQ("customer_credit_limit", array(
                    "customer_id"  => $customerId,
                    "limit_amount" => $limitAmount,
                    "currency_id"  => $limitCurrency,
                    "from_date"    => "now()",
                    "to_date"      => null
                )) . ";";
            }
        }

        // Periode Pembayaran
        $paymentPeriod   = floatval(str_replace(",", "", $_POST["payment_period"] ?? 0));
        $paymentPeriodId = intval($_POST["payment_period_id"] ?? 0);

        if ($paymentPeriod > 0) {
            if ($paymentPeriodId > 0) {
                $queryC .= updateQ("customer_payment_period", array("period" => $paymentPeriod), "id = " . $paymentPeriodId) . ";";
            } else {
                $queryC .= insertQ("customer_payment_period", array(
                    "customer_id" => $customerId,
                    "period"      => $paymentPeriod,
                    "from_date"   => "now()",
                    "to_date"     => null
                )) . ";";
            }
        }

        // Kontak Utama
        $contactsConfig = [
            "office_email"  => ["id" => "office_email_id", "type" => 1],
            "office_mobile" => ["id" => "office_mobile_id", "type" => 2],
            "office_phone"  => ["id" => "office_phone_id", "type" => 3],
            "office_fax"    => ["id" => "office_fax_id", "type" => 4]
        ];

        foreach ($contactsConfig as $postKey => $cfg) {
            if (!empty($_POST[$postKey])) {
                $contactNoId = intval($_POST[$cfg["id"]] ?? 0);
                if ($contactNoId > 0) {
                    $queryC .= updateQ("m_contact_no", array("contact_no" => checksinglequote($_POST[$postKey])), "id = " . $contactNoId) . ";";
                } else {
                    $queryC .= insertQ("m_contact_no", array(
                        "customer_id"  => $customerId,
                        "contact_type" => $cfg["type"],
                        "contact_no"   => checksinglequote($_POST[$postKey])
                    )) . ";";
                }
            }
        }

        // Save Kontak Person Tambahan
        if (is_array($dataContacts)) {
            foreach ($dataContacts as $contact) {
                $contId = intval($contact->cont_id ?? 0);
                if ($contId > 0) {
                    $queryC .= updateQ("m_contact_person", array(
                        "name"     => checksinglequote($contact->name ?? ''),
                        "position" => checksinglequote($contact->position ?? ''),
                        "remark"   => checksinglequote($contact->remark ?? '')
                    ), "id = " . $contId) . ";";

                    if (!empty($contact->address)) {
                        $contAddrId = intval($contact->cont_address_id ?? 0);
                        if ($contAddrId > 0) {
                            $queryC .= updateQ("m_address", array("address" => checksinglequote($contact->address)), "id = " . $contAddrId) . ";";
                        } else {
                            $queryC .= insertQ("m_address", array(
                                "contact_person_id" => $contId,
                                "active"            => 'true',
                                "address"           => checksinglequote($contact->address)
                            )) . ";";
                        }
                    }

                    $subContacts = [
                        "email"  => ["id" => "cont_email_id", "type" => 1],
                        "mobile" => ["id" => "cont_mobile_id", "type" => 2],
                        "phone"  => ["id" => "cont_phone_id", "type" => 3]
                    ];

                    foreach ($subContacts as $sKey => $sCfg) {
                        if (!empty($contact->$sKey)) {
                            $subId = intval($contact->{$sCfg["id"]} ?? 0);
                            if ($subId > 0) {
                                $queryC .= updateQ("m_contact_no", array("contact_no" => checksinglequote($contact->$sKey)), "id = " . $subId) . ";";
                            } else {
                                $queryC .= insertQ("m_contact_no", array(
                                    "contact_person_id" => $contId,
                                    "contact_type"      => $sCfg["type"],
                                    "contact_no"        => checksinglequote($contact->$sKey)
                                )) . ";";
                            }
                        }
                    }
                } else {
                    $queryC .= insertQ("m_contact_person", array(
                        "customer_id" => $customerId,
                        "name"        => checksinglequote($contact->name ?? ''),
                        "position"    => checksinglequote($contact->position ?? ''),
                        "remark"      => checksinglequote($contact->remark ?? '')
                    )) . ";";

                    if (!empty($contact->address)) {
                        $queryC .= insertQ("m_address", array(
                            "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                            "active"            => 'true',
                            "address"           => checksinglequote($contact->address)
                        )) . ";";
                    }

                    if (!empty($contact->email)) {
                        $queryC .= insertQ("m_contact_no", array(
                            "contact_type"      => 1,
                            "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                            "contact_no"        => checksinglequote($contact->email)
                        )) . ";";
                    }
                    if (!empty($contact->mobile)) {
                        $queryC .= insertQ("m_contact_no", array(
                            "contact_type"      => 2,
                            "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                            "contact_no"        => checksinglequote($contact->mobile)
                        )) . ";";
                    }
                    if (!empty($contact->phone)) {
                        $queryC .= insertQ("m_contact_no", array(
                            "contact_type"      => 3,
                            "contact_person_id" => "currval('m_contact_person_id_seq'::regclass)",
                            "contact_no"        => checksinglequote($contact->phone)
                        )) . ";";
                    }
                }
            }
        }

        $db->cmdQ($queryC);
        echo $db->getResult();
    break;

    // ==========================================
    // 7. DELETE (Soft Delete Customer)
    // ==========================================
    case ('delete'):
        $deleteId = intval($_POST["id"] ?? 0);

        if ($deleteId > 0) {
            $table  = "m_customer";
            $data   = array("active" => 'false');
            $where  = "id = " . $deleteId;

            $queryC = updateQ($table, $data, $where) . ";";
            $db->cmdQ($queryC);
            echo $db->getResult();
        } else {
            echo json_encode([
                "success" => "false",
                "status"  => "Error",
                "message" => "ID Customer tidak valid untuk proses penghapusan."
            ]);
        }
    break;

    default:
        echo json_encode([
            "success" => "false",
            "status"  => "Error",
            "message" => "Task atau perintah tidak dikenali oleh sistem."
        ]);
    break;
}
?>