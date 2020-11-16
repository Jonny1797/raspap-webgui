<?php

require_once 'includes/status_messages.php';
require_once 'config.php';

/**
 * Manage DHCP configuration
 */
function DisplayDHCPConfig()
{

    $status = new StatusMessages();
    if (!RASPI_MONITOR_ENABLED) {
        if (isset($_POST['savedhcpdsettings'])) {
            $errors = '';
            define('IFNAMSIZ', 16);
            if (!preg_match('/^[a-zA-Z0-9]+$/', $_POST['interface']) 
                || strlen($_POST['interface']) >= IFNAMSIZ
            ) {
                $errors .= _('Invalid interface name.').'<br />'.PHP_EOL;
            }

            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/', $_POST['RangeStart']) 
                && !empty($_POST['RangeStart'])
            ) {  // allow ''/null ?
                $errors .= _('Invalid DHCP range start.').'<br />'.PHP_EOL;
            }

            if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/', $_POST['RangeEnd']) 
                && !empty($_POST['RangeEnd'])
            ) {  // allow ''/null ?
                $errors .= _('Invalid DHCP range end.').'<br />'.PHP_EOL;
            }

            if (!ctype_digit($_POST['RangeLeaseTime']) && $_POST['RangeLeaseTimeUnits'] !== 'infinite') {
                $errors .= _('Invalid DHCP lease time, not a number.').'<br />'.PHP_EOL;
            }

            if (!in_array($_POST['RangeLeaseTimeUnits'], array('m', 'h', 'd', 'infinite'))) {
                $errors .= _('Unknown DHCP lease time unit.').'<br />'.PHP_EOL;
            }

            $return = 1;
            if (empty($errors)) {
                $config = 'interface='.$_POST['interface'].PHP_EOL.
                    'dhcp-range='.$_POST['RangeStart'].','.$_POST['RangeEnd'].
                    ',255.255.255.0,';
                if ($_POST['RangeLeaseTimeUnits'] !== 'infinite') {
                    $config .= $_POST['RangeLeaseTime'];
                }

                $config .= $_POST['RangeLeaseTimeUnits'].PHP_EOL;

                for ($i=0; $i < count($_POST["static_leases"]["mac"]); $i++) {
                    $mac = trim($_POST["static_leases"]["mac"][$i]);
                    $ip  = trim($_POST["static_leases"]["ip"][$i]);
                    if ($mac != "" && $ip != "") {
                        $config .= "dhcp-host=$mac,$ip".PHP_EOL;
                    }
                }

                if ($_POST['no-resolv'] == "1") {
                    $config .= "no-resolv".PHP_EOL;
                }
                foreach ($_POST['server'] as $server) {
                    $config .= "server=$server".PHP_EOL;
                }
                if ($_POST['log-dhcp'] == "1") {
                  $config .= "log-dhcp".PHP_EOL;
                }
                if ($_POST['log-queries'] == "1") {
                  $config .= "log-queries".PHP_EOL;
                }
                if ($_POST['DNS1']) {
                    $config .= "dhcp-option=6," . $_POST['DNS1'];
                    if ($_POST['DNS2']) {
                        $config .= ','.$_POST['DNS2'];
                    }
                    $config .= PHP_EOL;
                }

                $config .= "log-facility=/tmp/dnsmasq.log".PHP_EOL;
                $config .= "conf-dir=/etc/dnsmasq.d".PHP_EOL;
                file_put_contents("/tmp/dnsmasqdata", $config);

                $iface = $_POST['interface'];
                // handle DHCP for selected interface option
                if ($_POST['dhcp-iface'] == "1") {
                    $net_cfg = RASPI_CONFIG_NETWORKING.'/'.$iface.'.ini';
                    if (!file_exists($net_cfg) || filesize($net_cfg) ==0 ) {
                        $status->addMessage('Static IP address for '.$iface.' not found.', 'danger');
                        $status->addMessage('Configure this interface in Networking > '.$iface.'.', 'danger');
                        $return = 1;
                    } else {
                        $dhcp_cfg = file_get_contents(RASPI_DHCPCD_CONFIG);
                        if (!preg_match('/^interface\s'.$iface.'$/m', $dhcp_cfg)) {
                            // set dhcp values from ini
                            $iface_cfg = parse_ini_file($net_cfg, false, INI_SCANNER_RAW);
                            $ip_address = $iface_cfg['ip_address'];
                            $domain_name_server = ($iface_cfg['domain_name_server'] =='') ? '1.1.1.1 8.8.8.8' : $iface_cfg['domain_name_server'];

                            // append interface config to dhcpcd.conf
                            $cfg = $dhcp_conf;
                            $cfg[] = '# RaspAP '.$_POST['interface'].' configuration';
                            $cfg[] = 'interface '.$_POST['interface'];
                            $cfg[] = 'static ip_address='.$ip_address;
                            $cfg[] = 'static domain_name_server='.$domain_name_server;
                            $cfg[] = PHP_EOL;
                            $cfg = join(PHP_EOL, $cfg);
                            $dhcp_cfg .= $cfg;
                            file_put_contents("/tmp/dhcpddata", $dhcp_cfg);
                            system('sudo cp /tmp/dhcpddata '.RASPI_DHCPCD_CONFIG, $return);
                            $status->addMessage('DHCP configuration for '.$iface.' added.', 'success');
                            system('sudo cp /tmp/dnsmasqdata '.RASPI_DNSMASQ_PREFIX.$iface.'.conf', $return);
                            $status->addMessage('Dnsmasq configuration for '.$iface.' added.', 'success');
                        } else {
                            $status->addMessage('DHCP for '.$iface.' already enabled.', 'danger');
                        }
                    }
                } elseif (($_POST['dhcp-iface'] == "0") && file_exists(RASPI_DNSMASQ_PREFIX.$iface.'.conf')) {
                    // remove dhcp conf for selected interface
                    $dhcp_cfg = file_get_contents(RASPI_DHCPCD_CONFIG);
                    // todo: improve by removing extra blank lines
                    $dhcp_cfg = preg_replace('/^#\sRaspAP\s'.$iface.'.*\n(.*\n){3}/m', '', $dhcp_cfg);
                    file_put_contents("/tmp/dhcpddata", $dhcp_cfg);
                    system('sudo cp /tmp/dhcpddata '.RASPI_DHCPCD_CONFIG, $return);
                    $status->addMessage('DHCP configuration for '.$iface.'  removed.', 'success');
                    // remove dnsmasq eth0 conf
                    system('sudo rm '.RASPI_DNSMASQ_PREFIX.$iface.'.conf', $return);
                    $status->addMessage('Dnsmasq configuration for '.$iface.' removed.', 'success');
                } else {
                    system('sudo cp /tmp/dnsmasqdata '.RASPI_DNSMASQ_CONFIG, $return);
                }

            } else {
                $status->addMessage($errors, 'danger');
            }

            if ($return == 0) {
                $status->addMessage('Dnsmasq configuration updated successfully.', 'success');
            } else {
                $status->addMessage('Dnsmasq configuration failed to be updated.', 'danger');
            }
        }
    }

    exec('pidof dnsmasq | wc -l', $dnsmasq);
    $dnsmasq_state = ($dnsmasq[0] > 0);

    if (!RASPI_MONITOR_ENABLED) {
        if (isset($_POST['startdhcpd'])) {
            if ($dnsmasq_state) {
                $status->addMessage('dnsmasq already running', 'info');
            } else {
                exec('sudo /bin/systemctl start dnsmasq.service', $dnsmasq, $return);
                if ($return == 0) {
                    $status->addMessage('Successfully started dnsmasq', 'success');
                    $dnsmasq_state = true;
                } else {
                    $status->addMessage('Failed to start dnsmasq', 'danger');
                }
            }
        } elseif (isset($_POST['stopdhcpd'])) {
            if ($dnsmasq_state) {
                exec('sudo /bin/systemctl stop dnsmasq.service', $dnsmasq, $return);
                if ($return == 0) {
                    $status->addMessage('Successfully stopped dnsmasq', 'success');
                    $dnsmasq_state = false;
                } else {
                    $status->addMessage('Failed to stop dnsmasq', 'danger');
                }
            } else {
                $status->addMessage('dnsmasq already stopped', 'info');
            }
        }
    }

    $serviceStatus = $dnsmasq_state ? "up" : "down";
    exec("ip -o link show | awk -F': ' '{print $2}'", $interfaces);
    exec('cat ' . RASPI_DNSMASQ_LEASES, $leases);

    echo renderTemplate(
        "dhcp", compact(
            "status",
            "serviceStatus",
            "dnsmasq_state",
            "conf",
            "dhcpHost",
            "interfaces",
            "leases"
        )
    );
}
