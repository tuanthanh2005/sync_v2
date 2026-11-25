<?php

namespace App\Services\Social;

class HostIpResolver
{
    /**
     * Resolve public IPv4/IPv6 addresses for a host using system DNS.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $ips = [];

        $aRecords = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4List = @gethostbynamel($host);

            if (is_array($ipv4List)) {
                foreach ($ipv4List as $ip) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
