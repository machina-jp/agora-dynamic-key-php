<?php


$Privileges = array(
    "kJoinChannel" => 1,
    "kPublishAudioStream" => 2,
    "kPublishVideoStream" => 3,
    "kPublishDataStream" => 4,
    "kPublishAudioCdn" => 5,
    "kPublishVideoCdn" => 6,
    "kRequestPublishAudioStream" => 7,
    "kRequestPublishVideoStream" => 8,
    "kRequestPublishDataStream" => 9,
    "kInvitePublishAudioStream" => 10,
    "kInvitePublishVideoStream" => 11,
    "kInvitePublishDataStream" => 12,
    "kAdministrateChannel" => 101
);

class Message
{
    public $salt;
    public $ts;
    public $privileges;
    public function __construct()
    {
        $this->salt = rand(0, 100000);

        date_default_timezone_set("UTC");
        $date = new DateTime();
        $this->ts = $date->getTimestamp() + 24 * 3600;

        $this->privileges = array();
    }

    public function packContent()
    {
        $buffer = unpack("C*", pack("V", $this->salt));
        $buffer = array_merge($buffer, unpack("C*", pack("V", $this->ts)));
        $buffer = array_merge($buffer, unpack("C*", pack("v", sizeof($this->privileges))));
        foreach ($this->privileges as $key => $value) {
            $buffer = array_merge($buffer, unpack("C*", pack("v", $key)));
            $buffer = array_merge($buffer, unpack("C*", pack("V", $value)));
        }
        return $buffer;
    }

    public function unpackContent($msg){
        $pos = 0;
        $salt = unpack("V", substr($msg, $pos, 4))[1];
        $pos += 4;
        $ts = unpack("V", substr($msg, $pos, 4))[1];
        $pos += 4;
        $size = unpack("v", substr($msg, $pos, 2))[1];
        $pos += 2;

        $privileges = array();
        for($i = 0; $i < $size; $i++){
            $key = unpack("v", substr($msg, $pos, 2));
            $pos += 2;
            $value = unpack("V", substr($msg, $pos, 4));
            $pos += 4;
            $privileges[$key[1]] = $value[1];
        }
        $this->salt = $salt;
        $this->ts = $ts;
        $this->privileges = $privileges;
    }
}

class AccessToken
{
    protected $appID, $appCertificate, $channelName, $uid;
    public $message;
    protected $crc_channel_name, $crc_uid, $sig;

    public function __construct($appID, $appCertificate, $channelName, $uid)
    {
        $this->appID = $appID;
        $this->appCertificate = $appCertificate;
        $this->channelName = $channelName;

        if($uid === 0){
            $this->uid = "";
        } else {
            $this->uid = $uid . '';
        }

        $this->message = new Message();

        $this->sig = NULL;
        $this->crc_channel_name = NULL;
        $this->crc_uid = NULL;
    }

    public static function initWithToken($token){
        $accessToken = new AccessToken("", "", "", "");
        $accessToken->extract($token);
        return $accessToken;
    }

    public function addPrivilege($key, $expireTimestamp)
    {
        $this->message->privileges[$key] = $expireTimestamp;
        return $this;
    }

    protected function extract($token){
        $ver_len = 3;
        $appid_len = 32;
        $version = substr($token, 0, $ver_len);
        if($version !== "006" ){
            echo 'invalid version '.$version;
            return;
        }

        $appid = substr($token, $ver_len, $appid_len);
        $content = (base64_decode(substr($token, $ver_len + $appid_len, strlen($token) - ($ver_len + $appid_len))));

        $pos = 0;
        $len = unpack("v", $content.substr($pos, 2))[1];
        $pos += 2;
        $sig = substr($content, $pos, $len);
        $pos += $len;
        $crc_channel = unpack("V", substr($content, $pos, 4))[1];
        $pos += 4;
        $crc_uid = unpack("V", substr($content, $pos, 4))[1];
        $pos += 4;
        $msgLen = unpack("v", substr($content, $pos, 2))[1];
        $pos += 2;
        $msg = substr($content, $pos, $msgLen);

        $this->appID = $appid;
        $message = new Message();
        $message->unpackContent($msg);
        $this->message = $message;


        //non reversable values
        $this->sig = $sig;
        $this->crc_channel_name = $crc_channel;
        $this->crc_uid = $crc_uid;
    }

    public function build()
    {
        $msg = $this->message->packContent();
        $val = array_merge(unpack("C*", $this->appID), unpack("C*", $this->channelName), unpack("C*", $this->uid), $msg);
        
        $sig = NULL; $crc_channel_name = NULL; $crc_uid = NULL;
        //use sig if created from extraction
        if(is_null($this->sig)){
            $sig = hash_hmac('sha256', implode(array_map("chr", $val)), $this->appCertificate, true);
        } else {
            $sig = $this->sig;
        }

        if(is_null($this->crc_channel_name)){
            $crc_channel_name = crc32($this->channelName) & 0xffffffff;
        } else {
            $crc_channel_name = $this->crc_channel_name;
        }

        if(is_null($this->crc_channel_name)){
            $crc_uid = crc32($this->uid) & 0xffffffff;
        } else {
            $crc_uid = $this->crc_uid;
        }

        $content = array_merge(unpack("C*", packString($sig)), unpack("C*", pack("V", $crc_channel_name)), unpack("C*", pack("V", $crc_uid)), unpack("C*", pack("v", count($msg))), $msg);
        $version = "006";
        $ret = $version . $this->appID . base64_encode(implode(array_map("chr", $content)));
        return $ret;
    }
}

function packString($value)
{
    return pack("v", strlen($value)) . $value;
}
