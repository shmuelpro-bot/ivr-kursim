<?php
/**
 * yemot_api.php – מחלקת חיבור ל-API של ימות המשיח
 */

define('YEMOT_URL', 'https://' . (getenv('YEMOT_SERVER') ?: 'www') . '.call2all.co.il/ym/api/');
define('YEMOT_PHONE',    getenv('YEMOT_PHONE')    ?: '');
define('YEMOT_PASSWORD', getenv('YEMOT_PASSWORD') ?: '');

// ── HTTP multipart helper ───────────────────────────────────────

class BodyPost
{
    public static function PartPost($name, $val)
    {
        $body = 'Content-Disposition: form-data; name="' . $name . '"';
        if ($val instanceof oFile) {
            $body .= '; filename="' . $val->Name() . '"' . "\r\n";
            $body .= 'Content-Type: ' . $val->Mime() . "\r\n\r\n";
            $body .= $val->Content() . "\r\n";
        } else {
            $body .= "\r\n\r\n" . $val . "\r\n";
        }
        return $body;
    }

    public static function Get(array $post, $delimiter = '-------------0123456789')
    {
        if (empty($post)) throw new \Exception('Error input param!');
        $ret = '';
        foreach ($post as $name => $val)
            $ret .= '--' . $delimiter . "\r\n" . self::PartPost($name, $val);
        $ret .= '--' . $delimiter . "--\r\n";
        return $ret;
    }
}

// ── File wrapper ────────────────────────────────────────────────

class oFile
{
    private $name, $mime, $content;

    public function __construct($name, $mime = null, $content = null)
    {
        if (is_null($content)) {
            $info = pathinfo($name);
            if (empty($info['basename']) || !is_readable($name))
                throw new Exception('Error param');
            $this->name    = $info['basename'];
            $this->mime    = mime_content_type($name);
            $this->content = file_get_contents($name);
        } else {
            $this->name    = $name;
            $this->mime    = $mime ?? mime_content_type($name);
            $this->content = $content;
        }
    }

    public function Name()    { return $this->name; }
    public function Mime()    { return $this->mime; }
    public function Content() { return $this->content; }
}

// ── Yemot API class ─────────────────────────────────────────────

class YemotAPI
{
    public string $token = '';

    public function __construct(string $phone, string $password)
    {
        $body = http_build_query(['username' => $phone, 'password' => $password]);
        $opts = ['http' => [
            'method'          => 'POST',
            'header'          => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: Mozilla/5.0",
            'content'         => $body,
            'follow_location' => false,
        ]];
        $result = json_decode(file_get_contents(YEMOT_URL . 'Login', false, stream_context_create($opts)));
        if (!$result || $result->responseStatus !== 'OK')
            throw new Exception('שגיאת כניסה: ' . ($result->message ?? 'לא ידוע'));
        $this->token = $result->token;
    }

    public function __destruct()
    {
        if ($this->token) $this->call('Logout');
    }

    public function call(string $action, array $body = []): mixed
    {
        $delimiter    = '----' . uniqid();
        $body['token'] = $this->token;
        $content      = BodyPost::Get($body, $delimiter);
        $opts = ['http' => [
            'method'          => 'POST',
            'header'          => 'Content-Type: multipart/form-data; boundary=' . $delimiter,
            'content'         => $content,
            'follow_location' => false,
        ]];
        $raw     = file_get_contents(YEMOT_URL . $action, false, stream_context_create($opts));
        $headers = $this->parseHeaders($http_response_header);
        return ($headers['Content-Type'][0] ?? '') === 'application/json'
            ? json_decode($raw)
            : $raw;
    }

    private function parseHeaders(array $headers): array
    {
        $head = [];
        foreach ($headers as $v) {
            $t = explode(':', $v, 2);
            if (!isset($t[1])) {
                $head[] = $v;
                continue;
            }
            $key = trim($t[0]);
            if ($key === 'Content-Type') {
                $arr = [];
                foreach (explode(';', $t[1]) as $child) {
                    $c = explode('=', $child);
                    isset($c[1]) ? $arr[trim($c[0])] = trim($c[1]) : $arr[] = trim($c[0]);
                }
                $head[$key] = $arr;
            } else {
                $head[$key] = trim($t[1]);
            }
        }
        return $head;
    }
}
