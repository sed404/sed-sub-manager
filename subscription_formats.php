<?php
declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function subscription_yaml_parse(string $text): array
{
    if (!class_exists(Yaml::class)) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('缺少订阅解析依赖，请上传 vendor 目录。');
        require_once $autoload;
    }
    try { $value = Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE); }
    catch (Throwable $e) { throw new RuntimeException('上游 YAML 格式无效。'); }
    if (!is_array($value)) throw new RuntimeException('上游不是有效的订阅配置。');
    return $value;
}

function subscription_b64decode(string $text): string
{
    $decoded = base64_decode(strtr(preg_replace('/\s+/', '', $text), '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('订阅 Base64 编码无效。');
    return $decoded;
}

function subscription_uri_node(string $uri): array
{
    if (str_starts_with($uri, 'vmess://')) {
        $v = json_decode(subscription_b64decode(substr($uri, 8)), true, 64, JSON_THROW_ON_ERROR);
        $p = ['name'=>(string)($v['ps'] ?? 'VMess'), 'type'=>'vmess', 'server'=>$v['add'] ?? '', 'port'=>(int)($v['port'] ?? 0),
            'uuid'=>$v['id'] ?? '', 'alterId'=>(int)($v['aid'] ?? 0), 'cipher'=>$v['scy'] ?? 'auto', 'tls'=>($v['tls'] ?? '') === 'tls'];
        $network = $v['net'] ?? 'tcp';
        if (!in_array($network, ['tcp','ws','grpc','h2'], true) || !in_array($v['type'] ?? 'none', ['', 'none'], true)) throw new RuntimeException('暂不支持此 VMess 传输格式。');
        $p['network'] = $network;
        if (!empty($v['sni'])) $p['servername'] = $v['sni'];
        if (!empty($v['fp'])) $p['client-fingerprint'] = $v['fp'];
        if (!empty($v['alpn'])) $p['alpn'] = explode(',', $v['alpn']);
        if (isset($v['allowInsecure'])) $p['skip-cert-verify'] = filter_var($v['allowInsecure'], FILTER_VALIDATE_BOOLEAN);
        if ($network === 'ws') $p['ws-opts'] = ['path'=>($v['path'] ?? '') ?: '/', 'headers'=>['Host'=>$v['host'] ?? '']];
        if ($network === 'grpc') $p['grpc-opts'] = ['grpc-service-name'=>$v['path'] ?? ''];
        if ($network === 'h2') $p['h2-opts'] = ['path'=>$v['path'] ?? '/', 'host'=>explode(',', $v['host'] ?? '')];
        return $p;
    }
    if (str_starts_with($uri, 'ss://')) {
        $fragment = explode('#', substr($uri, 5), 2);
        $parts = explode('?', $fragment[0], 2);
        $authority = rtrim($parts[0], '/');
        if (!str_contains($authority, '@')) $authority = subscription_b64decode($authority);
        $at = strrpos($authority, '@');
        if ($at === false) throw new RuntimeException('SS 节点缺少地址。');
        $auth = rawurldecode(substr($authority, 0, $at));
        if (!str_contains($auth, ':')) $auth = subscription_b64decode($auth);
        [$method, $password] = array_pad(explode(':', $auth, 2), 2, '');
        $endpoint = parse_url('ss://' . substr($authority, $at + 1));
        $p = ['name'=>rawurldecode($fragment[1] ?? 'Shadowsocks'), 'type'=>'ss', 'server'=>trim($endpoint['host'] ?? '', '[]'), 'port'=>$endpoint['port'] ?? 0, 'cipher'=>$method, 'password'=>$password];
        parse_str($parts[1] ?? '', $q);
        if (!empty($q['plugin'])) {
            $options = explode(';', $q['plugin']); $plugin = array_shift($options);
            $p['plugin'] = $plugin === 'obfs-local' ? 'obfs' : $plugin;
            $p['plugin-opts'] = [];
            foreach ($options as $option) { [$k,$v] = array_pad(explode('=', $option, 2), 2, true); $p['plugin-opts'][$k] = $v; }
            if ($p['plugin'] === 'obfs') $p['plugin-opts'] = ['mode'=>$p['plugin-opts']['obfs'] ?? 'http', 'host'=>$p['plugin-opts']['obfs-host'] ?? ''];
        }
        return $p;
    }
    $v = parse_url($uri);
    if (!is_array($v)) throw new RuntimeException('节点链接无效。');
    $type = strtolower($v['scheme'] ?? '');
    if ($type === 'hy2') $type = 'hysteria2';
    if (!in_array($type, ['trojan','vless','hysteria2','tuic'], true)) throw new RuntimeException('暂不支持此上游 URI 协议。请提供 Clash YAML 订阅。');
    parse_str($v['query'] ?? '', $q);
    $known = ['type','security','encryption','sni','peer','host','path','serviceName','flow','fp','pbk','sid','alpn','allowInsecure','insecure','obfs','obfs-password','congestion_control','udp_relay_mode'];
    foreach ($q as $key=>$value) if (!in_array($key, $known, true) || !is_string($value)) throw new RuntimeException('上游 URI 含暂不支持的参数，请提供 Clash YAML 订阅。');
    if (isset($q['encryption']) && !in_array($q['encryption'], ['', 'none'], true)) throw new RuntimeException('不支持此 VLESS 加密参数。');
    $p = ['name'=>rawurldecode($v['fragment'] ?? strtoupper($type)), 'type'=>$type, 'server'=>trim($v['host'] ?? '', '[]'), 'port'=>$v['port'] ?? 443];
    $credential = rawurldecode($v['user'] ?? '');
    if (in_array($type, ['vless','tuic'], true)) $p['uuid'] = $credential; else $p['password'] = $credential . (isset($v['pass']) ? ':' . rawurldecode($v['pass']) : '');
    if ($type === 'tuic') $p['password'] = rawurldecode($v['pass'] ?? '');
    $p['tls'] = $type !== 'vless' || in_array($q['security'] ?? '', ['tls','reality'], true);
    $p['network'] = $q['type'] ?? 'tcp';
    if (!in_array($p['network'], ['tcp','ws','grpc','h2'], true)) throw new RuntimeException('暂不支持此上游传输方式。');
    if (!empty($q['sni']) || !empty($q['peer'])) $p['sni'] = $q['sni'] ?? $q['peer'];
    if (!empty($q['fp'])) $p['client-fingerprint'] = $q['fp'];
    if (!empty($q['flow'])) $p['flow'] = $q['flow'];
    if (!empty($q['alpn'])) $p['alpn'] = explode(',', $q['alpn']);
    $p['skip-cert-verify'] = filter_var($q['allowInsecure'] ?? $q['insecure'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (($q['security'] ?? '') === 'reality') $p['reality-opts'] = ['public-key'=>$q['pbk'] ?? '', 'short-id'=>$q['sid'] ?? ''];
    if ($p['network'] === 'ws') $p['ws-opts'] = ['path'=>$q['path'] ?? '/', 'headers'=>['Host'=>$q['host'] ?? '']];
    if ($p['network'] === 'grpc') $p['grpc-opts'] = ['grpc-service-name'=>$q['serviceName'] ?? ''];
    if ($p['network'] === 'h2') $p['h2-opts'] = ['path'=>$q['path'] ?? '/', 'host'=>explode(',', $q['host'] ?? '')];
    foreach (['obfs','obfs-password'] as $key) if (isset($q[$key])) $p[$key] = $q[$key];
    if (isset($q['congestion_control'])) $p['congestion-controller'] = $q['congestion_control'];
    if (isset($q['udp_relay_mode'])) $p['udp-relay-mode'] = $q['udp_relay_mode'];
    return $p;
}

function normalize_upstream_subscription(string $body): string
{
    $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
    if (preg_match('/^(?:<!doctype|<html)/i', $body)) throw new RuntimeException('上游返回网页而不是节点订阅。');
    if (preg_match('/(?:^|\n)proxies\s*:/', $body) || str_starts_with($body, '{')) {
        $doc = subscription_yaml_parse($body);
        $nodes = $doc['proxies'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes)) throw new RuntimeException('订阅必须包含 proxies 节点列表，不支持仅包含远程 provider 的配置。');
    } else {
        if (!preg_match('/^[a-z0-9]+:\/\//i', $body)) $body = subscription_b64decode($body);
        $nodes = [];
        foreach (preg_split('/\R/', trim($body)) as $uri) {
            if (trim($uri) === '') continue;
            try { $nodes[] = subscription_uri_node(trim($uri)); }
            catch (Throwable $e) { throw new RuntimeException('第 ' . (count($nodes) + 1) . ' 个上游节点格式不支持或数据无效，请使用 Clash YAML 上游。'); }
        }
    }
    if (!$nodes || count($nodes) > 10000) throw new RuntimeException('节点列表为空或超过 10000 个节点。');
    $yaml = "proxies:\n";
    foreach ($nodes as $node) {
        if (!is_array($node) || !is_string($node['name'] ?? null) || !is_string($node['server'] ?? null) || !is_string($node['type'] ?? null)
            || trim($node['server']) === '' || preg_match('/[\s\/?#@]/u', $node['server'])
            || trim($node['name']) === '' || preg_match('/[\x00-\x1f\x7f]/u', $node['name']) || strlen($node['name']) > 1000
            || !filter_var($node['port'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]])) throw new RuntimeException('上游节点缺少有效的名称、协议、地址或端口。');
        $name = $node['name']; unset($node['name']); ksort($node);
        $node['type'] = strtolower($node['type']);
        foreach (['ws-opts','grpc-opts','h2-opts','reality-opts','plugin-opts','alpn'] as $key) if (isset($node[$key]) && !is_array($node[$key])) throw new RuntimeException('上游节点选项格式无效。');
        $node['port'] = (int)$node['port'];
        // Flow mappings keep the existing override keys/parser stable and quote credentials safely.
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $yaml .= '  - {name: ' . clash_yaml_quote($name) . ', ' . substr($json, 1) . "\n";
    }
    return $yaml;
}

function subscription_endpoint(array $p): string
{
    $host = trim((string)$p['server'], '[]');
    return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . (int)$p['port'];
}

/** Reject options that cannot be represented rather than produce broken nodes. */
function subscription_conversion_guard(array $p, bool $allowXhttp = false): void
{
    $required = match ($p['type'] ?? '') {
        'ss' => ['cipher', 'password'], 'vmess', 'vless' => ['uuid'],
        'trojan', 'hysteria2' => ['password'], 'tuic' => ['uuid', 'password'], default => [],
    };
    foreach ($required as $key) if (!isset($p[$key]) || !is_scalar($p[$key]) || (string)$p[$key] === '') throw new RuntimeException('节点凭据不完整。');
    if (($p['type'] ?? '') === 'ss' && (!empty($p['tls']) || !empty($p['reality-opts']))) throw new RuntimeException('暂不转换带额外 TLS 封装的 SS 节点。');
    foreach (['dialer-proxy','fingerprint','certificate','private-key','ech-opts','smux','packet-encoding','http-opts','xhttp-opts','ports','ip-version'] as $key) {
        if ($key === 'xhttp-opts' && $allowXhttp && ($p['network'] ?? '') === 'xhttp') continue;
        if (!empty($p[$key])) throw new RuntimeException('该节点含目标格式尚未支持的高级参数。');
    }
    if (!in_array($p['network'] ?? 'tcp', $allowXhttp ? ['tcp','ws','grpc','h2','xhttp'] : ['tcp','ws','grpc','h2'], true)) throw new RuntimeException('不支持此传输方式。');
    if (!empty($p['ws-opts']['max-early-data']) || !empty($p['ws-opts']['early-data-header-name'])) throw new RuntimeException('暂不转换 WebSocket early data 参数。');
    foreach (($p['ws-opts']['headers'] ?? []) as $key=>$value) if (strtolower($key) !== 'host') throw new RuntimeException('暂不转换自定义 WebSocket 请求头。');
}

function subscription_plugin(array $p): string
{
    $plugin = $p['plugin'] ?? ''; $o = $p['plugin-opts'] ?? [];
    if ($plugin === '') return '';
    if ($plugin === 'obfs') return 'obfs-local;obfs=' . ($o['mode'] ?? 'http') . ';obfs-host=' . ($o['host'] ?? '');
    if ($plugin === 'v2ray-plugin') {
        if (!in_array($o['mode'] ?? 'websocket', ['websocket'], true) || !empty($o['mux'])) throw new RuntimeException('不支持此 SS 插件参数。');
        return 'v2ray-plugin;mode=websocket;host=' . ($o['host'] ?? '') . ';path=' . ($o['path'] ?? '/') . (!empty($o['tls']) ? ';tls' : '');
    }
    throw new RuntimeException('不支持此 SS 插件。');
}

function subscription_xhttp_query(array $p): array
{
    $o = $p['xhttp-opts'] ?? [];
    $allowed = ['path','host','mode','download-settings','headers','no-grpc-header','x-padding-bytes','sc-max-each-post-bytes','sc-min-posts-interval-ms'];
    foreach ($o as $key=>$value) if (!in_array($key, $allowed, true)) throw new RuntimeException('暂不支持此 XHTTP 参数。');
    $mode = $o['mode'] ?? 'auto';
    if (!in_array($mode, ['auto','stream-one','stream-up','packet-up'], true)) throw new RuntimeException('XHTTP 模式无效。');
    $q = ['path'=>$o['path'] ?? '/', 'host'=>($o['host'] ?? '') ?: ($p['servername'] ?? $p['sni'] ?? $p['server']), 'mode'=>$mode];
    $extra = [];
    foreach (['headers'=>'headers','no-grpc-header'=>'noGRPCHeader','x-padding-bytes'=>'xPaddingBytes','sc-max-each-post-bytes'=>'scMaxEachPostBytes','sc-min-posts-interval-ms'=>'scMinPostsIntervalMs'] as $from=>$to) {
        if (array_key_exists($from, $o)) $extra[$to] = $o[$from];
    }
    if (!empty($o['download-settings'])) {
        if ($mode === 'stream-one') throw new RuntimeException('stream-one 不支持上下行分离。');
        $d = $o['download-settings'];
        foreach ($d as $key=>$value) if (!in_array($key, ['path','host','headers','server','port','tls','alpn','servername','client-fingerprint','skip-cert-verify','reality-opts'], true)) throw new RuntimeException('暂不支持此 XHTTP 下载参数。');
        // Mihomo inherits omitted download settings from the upload connection.
        $effective = array_replace($p, $d);
        $tls = !empty($effective['tls']);
        $reality = $effective['reality-opts'] ?? [];
        $x = array_merge(['path'=>$d['path'] ?? $q['path'], 'host'=>($d['host'] ?? $o['host'] ?? '') ?: ($effective['servername'] ?? $effective['sni'] ?? $effective['server'])], $extra);
        if (isset($d['headers'])) $x['headers'] = $d['headers'];
        $download = ['address'=>$effective['server'], 'port'=>(int)$effective['port'], 'network'=>'xhttp', 'security'=>$tls ? ($reality ? 'reality' : 'tls') : 'none', 'xhttpSettings'=>$x];
        if ($tls) {
            $settings = ['serverName'=>$effective['servername'] ?? $effective['sni'] ?? $effective['server'], 'fingerprint'=>$effective['client-fingerprint'] ?? 'chrome'];
            if ($reality) { $settings['publicKey']=$reality['public-key']; $settings['shortId']=$reality['short-id'] ?? ''; }
            else { $settings['allowInsecure']=!empty($effective['skip-cert-verify']); if (!empty($effective['alpn'])) $settings['alpn']=$effective['alpn']; }
            $download[$reality ? 'realitySettings' : 'tlsSettings'] = $settings;
        }
        $extra['downloadSettings'] = $download;
    }
    if ($extra) $q['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return $q;
}

function subscription_node_uri(array $p): string
{
    subscription_conversion_guard($p, ($p['type'] ?? '') === 'vless');
    $type = $p['type']; $network = $p['network'] ?? 'tcp';
    $name = rawurlencode($p['name']); $endpoint = subscription_endpoint($p);
    if ($type === 'ss') {
        $plugin = subscription_plugin($p);
        return 'ss://' . rtrim(strtr(base64_encode($p['cipher'] . ':' . $p['password']), '+/', '-_'), '=') . '@' . $endpoint
            . ($plugin !== '' ? '/?plugin=' . rawurlencode($plugin) : '') . '#' . $name;
    }
    if ($type === 'vmess') {
        if (!empty($p['reality-opts'])) throw new RuntimeException('VMess Reality 无法表示为此分享格式。');
        $v = ['v'=>'2','ps'=>$p['name'],'add'=>$p['server'],'port'=>(string)$p['port'],'id'=>$p['uuid'],'aid'=>(string)($p['alterId'] ?? 0),
            'scy'=>$p['cipher'] ?? 'auto','net'=>$network,'type'=>'none','host'=>$p['ws-opts']['headers']['Host'] ?? implode(',', $p['h2-opts']['host'] ?? []),
            'path'=>$p['ws-opts']['path'] ?? $p['grpc-opts']['grpc-service-name'] ?? $p['h2-opts']['path'] ?? '',
            'tls'=>!empty($p['tls']) ? 'tls' : '', 'sni'=>$p['servername'] ?? $p['sni'] ?? '', 'fp'=>$p['client-fingerprint'] ?? '',
            'alpn'=>implode(',', $p['alpn'] ?? []), 'allowInsecure'=>!empty($p['skip-cert-verify']) ? '1' : '0'];
        return 'vmess://' . base64_encode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    if (!in_array($type, ['trojan','vless','hysteria2','tuic'], true)) throw new RuntimeException('目标 URI 格式不支持此协议。');
    $q = [];
    if (in_array($type, ['trojan','vless'], true)) {
        $q['type'] = $network;
        $q['security'] = !empty($p['reality-opts']) ? 'reality' : (($type === 'trojan' || !empty($p['tls'])) ? 'tls' : 'none');
        if ($type === 'vless') $q['encryption'] = 'none';
        if ($network === 'ws') { $q['path']=$p['ws-opts']['path'] ?? '/'; $q['host']=$p['ws-opts']['headers']['Host'] ?? ''; }
        if ($network === 'grpc') $q['serviceName']=$p['grpc-opts']['grpc-service-name'] ?? '';
        if ($network === 'h2') { $q['path']=$p['h2-opts']['path'] ?? '/'; $q['host']=implode(',', $p['h2-opts']['host'] ?? []); }
        if ($network === 'xhttp') $q = array_merge($q, subscription_xhttp_query($p));
        if (!empty($p['flow'])) $q['flow']=$p['flow'];
    }
    $q['sni'] = $p['servername'] ?? $p['sni'] ?? '';
    if (!empty($p['alpn'])) $q['alpn']=implode(',', $p['alpn']);
    if (!empty($p['client-fingerprint'])) $q['fp']=$p['client-fingerprint'];
    if (!empty($p['skip-cert-verify'])) $q['allowInsecure']='1';
    if (!empty($p['reality-opts'])) { $q['pbk']=$p['reality-opts']['public-key']; $q['sid']=$p['reality-opts']['short-id'] ?? ''; }
    if ($type === 'hysteria2') {
        if (!empty($p['obfs'])) { $q['obfs']=$p['obfs']; $q['obfs-password']=$p['obfs-password'] ?? ''; }
        if (isset($q['allowInsecure'])) { $q['insecure']='1'; unset($q['allowInsecure']); }
    }
    if ($type === 'tuic') {
        $q['congestion_control']=$p['congestion-controller'] ?? 'cubic';
        $q['udp_relay_mode']=$p['udp-relay-mode'] ?? 'native';
    }
    $credential = in_array($type, ['vless','tuic'], true) ? $p['uuid'] : $p['password'];
    $auth = rawurlencode((string)$credential) . ($type === 'tuic' ? ':' . rawurlencode((string)$p['password']) : '');
    return $type . '://' . $auth . '@' . $endpoint . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) . '#' . $name;
}

function subscription_singbox_node(array $p): array
{
    subscription_conversion_guard($p);
    $type = $p['type'];
    if (!in_array($type, ['ss','vmess','vless','trojan','hysteria2','tuic'], true)) throw new RuntimeException('sing-box 转换暂不支持此协议。');
    $out = ['type'=>$type === 'ss' ? 'shadowsocks' : $type,'tag'=>$p['name'],'server'=>$p['server'],'server_port'=>(int)$p['port']];
    if ($type === 'ss') {
        $out['method']=$p['cipher']; $out['password']=(string)$p['password'];
        $plugin = subscription_plugin($p);
        if ($plugin !== '') { [$out['plugin'], $out['plugin_opts']]=array_pad(explode(';', $plugin, 2), 2, ''); }
    }
    if (in_array($type, ['vmess','vless','tuic'], true)) $out['uuid']=$p['uuid'];
    if (in_array($type, ['trojan','hysteria2','tuic'], true)) $out['password']=(string)$p['password'];
    if ($type === 'vmess') { $out['security']=$p['cipher'] ?? 'auto'; $out['alter_id']=(int)($p['alterId'] ?? 0); }
    if ($type === 'vless' && !empty($p['flow'])) $out['flow']=$p['flow'];
    if ($type === 'tuic') { $out['congestion_control']=$p['congestion-controller'] ?? 'cubic'; $out['udp_relay_mode']=$p['udp-relay-mode'] ?? 'native'; $out['zero_rtt_handshake']=!empty($p['reduce-rtt']); }
    if ($type === 'hysteria2' && !empty($p['obfs'])) $out['obfs']=['type'=>$p['obfs'],'password'=>(string)($p['obfs-password'] ?? '')];
    if (!empty($p['tls']) || in_array($type, ['trojan','hysteria2','tuic'], true)) {
        $out['tls']=['enabled'=>true, 'server_name'=>$p['servername'] ?? $p['sni'] ?? $p['server'], 'insecure'=>!empty($p['skip-cert-verify'])];
        if (!empty($p['alpn'])) $out['tls']['alpn']=$p['alpn'];
        if (!empty($p['client-fingerprint']) && in_array($type,['vmess','vless','trojan'],true)) $out['tls']['utls']=['enabled'=>true,'fingerprint'=>$p['client-fingerprint']];
        if (!empty($p['reality-opts'])) {
            $out['tls']['utls']=['enabled'=>true,'fingerprint'=>$p['client-fingerprint'] ?? 'chrome'];
            $out['tls']['reality']=['enabled'=>true,'public_key'=>$p['reality-opts']['public-key'],'short_id'=>$p['reality-opts']['short-id'] ?? ''];
        }
    }
    $network = $p['network'] ?? 'tcp';
    if (in_array($type,['vmess','vless','trojan'],true) && $network !== 'tcp') {
        if ($network === 'ws') $out['transport']=['type'=>'ws','path'=>$p['ws-opts']['path'] ?? '/','headers'=>(object)($p['ws-opts']['headers'] ?? [])];
        if ($network === 'grpc') $out['transport']=['type'=>'grpc','service_name'=>$p['grpc-opts']['grpc-service-name'] ?? ''];
        if ($network === 'h2') $out['transport']=['type'=>'http','path'=>$p['h2-opts']['path'] ?? '/','host'=>$p['h2-opts']['host'] ?? []];
    }
    return $out;
}

function subscription_quantumult_node(array $p): string
{
    subscription_conversion_guard($p);
    if (!in_array($p['type'], ['ss','vmess','vless','trojan'], true) || !in_array($p['network'] ?? 'tcp', ['tcp','ws'], true)) throw new RuntimeException('此节点不支持 Quantumult X 转换。');
    $network=$p['network'] ?? 'tcp'; $tls=!empty($p['tls']) || $p['type']==='trojan';
    $tlsHost=$p['servername'] ?? $p['sni'] ?? $p['server'];
    if ($tls && $network === 'ws' && !empty($p['ws-opts']['headers']['Host']) && $p['ws-opts']['headers']['Host'] !== $tlsHost) throw new RuntimeException('Quantumult X 无法独立表示此 WebSocket Host 和 TLS SNI。');
    $parts=[($p['type']==='ss'?'shadowsocks':$p['type']) . '=' . subscription_endpoint($p)];
    if ($p['type'] !== 'trojan') $parts[]='method=' . ($p['type'] === 'vless' ? 'none' : (($p['cipher'] ?? 'auto') === 'auto' ? 'aes-128-gcm' : $p['cipher']));
    $parts[]='password=' . (in_array($p['type'], ['vmess','vless'], true) ? $p['uuid'] : $p['password']);
    if ($p['type']==='vmess') $parts[]='aead=' . ((int)($p['alterId'] ?? 0) === 0 ? 'true' : 'false');
    if ($network==='ws') { $parts[]='obfs=' . ($tls?'wss':'ws'); $parts[]='obfs-uri=' . ($p['ws-opts']['path'] ?? '/'); $parts[]='obfs-host=' . ($p['ws-opts']['headers']['Host'] ?? $p['servername'] ?? $p['sni'] ?? $p['server']); }
    elseif ($tls) $parts[]=$p['type']==='trojan' ? 'over-tls=true' : 'obfs=over-tls';
    if ($tls) $parts[]='tls-verification=' . (!empty($p['skip-cert-verify'])?'false':'true');
    if ($tls && $network === 'tcp' && $p['type'] === 'trojan') $parts[]='tls-host=' . $tlsHost;
    if ($tls && $network === 'tcp' && in_array($p['type'], ['vmess','vless'], true)) $parts[]='obfs-host=' . ($p['servername'] ?? $p['sni'] ?? $p['server']);
    if (!empty($p['reality-opts'])) { $parts[]='reality-base64-pubkey=' . $p['reality-opts']['public-key']; $parts[]='reality-hex-shortid=' . ($p['reality-opts']['short-id'] ?? ''); }
    if ($p['type'] === 'vless' && !empty($p['flow'])) $parts[]='vless-flow=' . $p['flow'];
    if (!empty($p['alpn'])) {
        $alpn=''; foreach ($p['alpn'] as $value) { if (strlen($value)>255) throw new RuntimeException('ALPN 无效。'); $alpn.=sprintf('%02x',strlen($value)).bin2hex($value); }
        $parts[]='tls-alpn=' . $alpn;
    }
    if ($p['type']==='ss' && !empty($p['plugin'])) {
        if ($p['plugin']!=='obfs') throw new RuntimeException('Quantumult X 不支持此 SS 插件转换。');
        $parts[]='obfs=' . ($p['plugin-opts']['mode'] ?? 'http'); $parts[]='obfs-host=' . ($p['plugin-opts']['host'] ?? '');
    }
    $parts[]='udp-relay=' . (!isset($p['udp']) || $p['udp'] ? 'true':'false');
    // Quantumult resource syntax is comma-delimited; do not alter passwords to escape it.
    foreach ($parts as $part) if (preg_match('/[,\r\n]/', $part)) throw new RuntimeException('Quantumult X 参数含无法表示的分隔符。');
    $parts[]='tag=' . str_replace([',',"\r","\n"], ['，',' ',' '], $p['name']);
    return implode(', ', $parts);
}

function convert_client_subscription(string $yaml, string $client): array
{
    $doc=subscription_yaml_parse($yaml); $nodes=$doc['proxies'] ?? [];
    if (!$nodes) throw new RuntimeException('没有可输出的启用节点。');
    $converted=[]; $skipped=0;
    foreach ($nodes as $node) {
        try {
            if ($client==='clashmeta') $value=$node;
            elseif ($client==='clash') {
                if (!in_array($node['type'], ['ss','ssr','vmess','trojan','socks5','http'], true) || !empty($node['reality-opts']) || str_starts_with($node['cipher'] ?? '', '2022-')) throw new RuntimeException('经典 Clash 不支持此节点。');
                $value=$node;
            } elseif (in_array($client,['v2ray','shadowrocket'],true)) $value=subscription_node_uri($node);
            elseif ($client==='singbox') $value=subscription_singbox_node($node);
            elseif ($client==='quantumultx') $value=subscription_quantumult_node($node);
            else throw new InvalidArgumentException('不支持的客户端类型。');
            $converted[]=$value;
        } catch (RuntimeException $e) { $skipped++; }
    }
    if (!$converted) throw new RuntimeException('当前线路没有此客户端可用的节点，请使用 Clash Meta / Verge Rev，或联系管理员检查节点协议。');
    $mime='text/plain; charset=utf-8'; $ext='txt';
    if (in_array($client,['clash','clashmeta'],true)) {
        $doc['proxies']=$converted;
        $allowed=array_merge(array_column($converted,'name'), array_column($doc['proxy-groups'] ?? [],'name'), ['DIRECT','REJECT']);
        if (!isset($doc['proxy-groups'])) $doc['proxy-groups']=[];
        foreach ($doc['proxy-groups'] as &$group) $group['proxies']=array_values(array_filter($group['proxies'] ?? [],fn($name)=>in_array($name,$allowed,true)));
        unset($group);
        $body=Yaml::dump($doc, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK); $mime='application/yaml; charset=utf-8'; $ext='yaml';
    } elseif ($client==='singbox') {
        $tags=array_column($converted,'tag'); $selector='节点选择'; while(in_array($selector,$tags,true)) $selector.='·';
        $direct='直连'; while(in_array($direct,$tags,true) || $direct===$selector) $direct.='·';
        $doc=['log'=>['level'=>'warn'],
            'dns'=>['servers'=>[['type'=>'udp','tag'=>'dns-local','server'=>'223.5.5.5'],['type'=>'https','tag'=>'dns-proxy','server'=>'1.1.1.1','detour'=>$selector]],'final'=>'dns-proxy','strategy'=>'ipv4_only'],
            'inbounds'=>[['type'=>'tun','tag'=>'tun-in','address'=>['172.19.0.1/30'],'auto_route'=>true,'strict_route'=>true],['type'=>'mixed','tag'=>'mixed-in','listen'=>'127.0.0.1','listen_port'=>7890]],
            'outbounds'=>array_merge([['type'=>'selector','tag'=>$selector,'outbounds'=>$tags,'default'=>$tags[0]],['type'=>'direct','tag'=>$direct]],$converted),
            'route'=>['auto_detect_interface'=>true,'default_domain_resolver'=>'dns-local','rules'=>[['action'=>'sniff'],['protocol'=>'dns','action'=>'hijack-dns'],['ip_is_private'=>true,'outbound'=>$direct]],'final'=>$selector]];
        $body=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); $mime='application/json; charset=utf-8'; $ext='json';
    } else {
        $body=implode("\n",$converted)."\n";
        if (in_array($client,['v2ray','shadowrocket'],true)) $body=base64_encode($body);
    }
    return ['body'=>$body,'mime'=>$mime,'extension'=>$ext,'count'=>count($converted),'skipped'=>$skipped];
}
