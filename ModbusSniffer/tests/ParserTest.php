<?php
declare(strict_types=1);
const KL_WARNING = 10206;
class IPSModule {
    public array $props = []; public array $buf = []; public array $children = []; public int $status = 0; public array $debug = [];
    public function __construct() {}
    public function Create() {} public function ApplyChanges() {}
    protected function RegisterPropertyInteger($n,$v){ $this->props[$n] ??= $v; }
    protected function RegisterPropertyBoolean($n,$v){ $this->props[$n] ??= $v; }
    protected function ReadPropertyInteger($n){ return $this->props[$n]; }
    protected function ReadPropertyBoolean($n){ return $this->props[$n]; }
    protected function SetBuffer($n,$v){ $this->buf[$n]=$v; }
    protected function GetBuffer($n){ return $this->buf[$n] ?? ''; }
    protected function GetBufferList(){ return array_keys($this->buf); }
    protected function ForceParent($g){}
    protected function SetStatus($s){ $this->status=$s; }
    protected function SendDebug($a,$b,$c){ $this->debug[]="$a: $b"; }
    protected function LogMessage($m,$t){}
    protected function SendDataToChildren($j){ $this->children[] = json_decode($j,true); }
    protected function SendDataToParent($j){ throw new Exception('SendDataToParent aufgerufen!'); }
}
require __DIR__.'/../module.php';
function rtu(string $hex): string { $b = hex2bin($hex); return $b . ModbusSniffer::CRC16($b); }
function rx($m, string $bin, ?string $client=null) {
    $buf = mb_convert_encoding($bin, 'UTF-8', 'ISO-8859-1');
    if ($client) { [$ip,$port]=explode(':',$client); $m->ReceiveData(json_encode(['DataID'=>'{7A1272A4-C6A7-4E25-9A62-AD07AD8BE2C7}','Buffer'=>$buf,'ClientIP'=>$ip,'ClientPort'=>(int)$port,'Type'=>0])); }
    else $m->ReceiveData(json_encode(['DataID'=>'{018EF6B5-AB94-40C6-AA53-46943E824ACF}','Buffer'=>$buf]));
}
function check($cond, $msg) { echo ($cond ? "OK   " : "FAIL ") . $msg . "\n"; if (!$cond) $GLOBALS['fail']=true; }
$fail=false;

// ---------- RTU seriell ----------
$m = new ModbusSniffer(); $m->Create(); $m->props['FrameGap']=0; $m->ApplyChanges();
$stream = "\x00\xFF" // Müll
  . rtu('010300640002')            // FC3 req Adr 100, 2 Reg
  . rtu('010304' . '1234ABCD')     // Antwort
  . rtu('01060010' . '00FF')       // FC6 req
  . rtu('01060010' . '00FF')       // FC6 Echo
  . rtu('0110002000020400010002')  // FC16 req
  . rtu('011000200002')            // FC16 resp
  . rtu('020100000005')            // FC1 req Unit2, 5 Coils
  . rtu('02010115')                // resp 0b10101
  . rtu('010300C80001') . rtu('018302'); // Exception
// in zufälligen Stücken einspeisen
mt_srand(3); $i=0; while ($i < strlen($stream)) { $n = mt_rand(1,7); rx($m, substr($stream,$i,$n)); $i+=$n; }
$types = array_map(fn($c)=>$c['Type'].'/FC'.$c['Function'], $m->children);
echo implode(', ', $types), "\n";
check(count($m->children) === 10, '10 Frames erkannt');
$st = $m->GetStatistics(); check($st['Frames']===10 && $st['Errors']===1, 'Statistik Frames=10, Müll als 1 Fehler (Errors='.$st['Errors'].')');
check($m->GetCachedValues(1,'hr',100,2)[100]['Value']===0x1234 && $m->GetCachedValues(1,'hr',100,2)[101]['Value']===0xABCD, 'FC3 Werte im Cache');
check($m->GetCachedValues(1,'hr',16,1)[16]['Value']===0xFF, 'FC6 Schreibwert im Cache');
check($m->GetCachedValues(1,'hr',32,2)[33]['Value']===2, 'FC16 Werte im Cache');
check(array_column($m->GetCachedValues(2,'co',0,5),'Value')===[1,0,1,0,1], 'FC1 Coils Unit 2');
check(end($m->children)['Type']==='exception' && end($m->children)['Exception']===2, 'Exception erkannt');

// ---------- ForwardData (Blockieren) ----------
$req = fn($fc,$a,$q)=>json_encode(['DataID'=>'{E310B701-4AE7-458E-B618-EC13A1A6F6A8}','Function'=>$fc,'Address'=>$a,'Quantity'=>$q,'Data'=>'']);
$ans = $m->ForwardData($req(3,100,2));
check($ans === "\x03\x04\x12\x34\xAB\xCD", 'Leseanfrage aus Cache beantwortet: '.bin2hex((string)$ans));
check(substr($ans,2) === hex2bin('1234abcd'), 'substr(...,2) liefert Registerdaten');
check($m->ForwardData($req(3,500,1)) === false, 'Unbekannte Register -> blockiert (false)');
check($m->ForwardData($req(6,16,1)) === false, 'Schreibanfrage -> blockiert (false)');
$m->props['DeviceID']=2;
check($m->ForwardData($req(1,0,5)) === "\x01\x01\x15", 'Coils aus Cache für Geräte-ID 2');
$m->props['MaxAge']=1; $m->buf['C_2_co'] = json_encode([0=>[1, microtime(true)-5]]);
check($m->ForwardData($req(1,0,1)) === false, 'Veraltete Werte -> blockiert');
check($m->GetStatistics()['Blocked']===5, 'Blocked-Zähler');

// ---------- Ruhepause verwirft Rest ----------
$g = new ModbusSniffer(); $g->Create(); $g->props['FrameGap']=20; $g->ApplyChanges();
rx($g, substr(rtu('010304AAAABBBB'),0,5)); // abgebrochener Frame (Antwort, kein Request)
usleep(40000);
rx($g, rtu('010300000001')); rx($g, rtu('01030200FF'));
check(count($g->children)===2 && $g->GetCachedValues(1,'hr',0,1)[0]['Value']===255, 'Nach Ruhepause neu synchronisiert');

// ---------- Modbus TCP über Server Socket ----------
$t = new ModbusSniffer(); $t->Create(); $t->props['InterfaceType']=1; $t->props['Protocol']=1; $t->props['FrameGap']=0; $t->ApplyChanges();
$mbap = fn($tid,$unit,$pdu)=>pack('nnnC',$tid,0,strlen(hex2bin($pdu))+1,$unit).hex2bin($pdu);
$s = $mbap(7,1,'0400000003') . $mbap(9,1,'0300100001') . $mbap(7,1,'0406000100020003') . $mbap(9,1,'03020005');
rx($t, substr($s,0,10), '10.0.0.5:5020'); rx($t, substr($s,10), '10.0.0.5:5020');
check(count($t->children)===4, 'MBAP: 4 Frames');
check(array_column($t->GetCachedValues(1,'ir',0,3),'Value')===[1,2,3], 'MBAP: FC4 über Transaction-ID gepaart (verschachtelt)');
check($t->GetCachedValues(1,'hr',16,1)[16]['Value']===5, 'MBAP: FC3 gepaart');
// RTU over TCP
$r = new ModbusSniffer(); $r->Create(); $r->props['InterfaceType']=1; $r->props['FrameGap']=0; $r->ApplyChanges();
rx($r, rtu('050300010001').rtu('05030200AA'), '10.0.0.6:4001');
check($r->GetCachedValues(5,'hr',1,1)[1]['Value']===0xAA, 'RTU over TCP über Server Socket');
$t->ReceiveData(json_encode(['DataID'=>'{7A1272A4-C6A7-4E25-9A62-AD07AD8BE2C7}','Buffer'=>'','ClientIP'=>'10.0.0.5','ClientPort'=>5020,'Type'=>2]));
check(true, 'SendDataToParent wurde in keinem Test aufgerufen');
// TCP auf seriell -> Fehlerstatus
$e = new ModbusSniffer(); $e->Create(); $e->props['Protocol']=1; $e->ApplyChanges(); check($e->status===201, 'Status 201 bei TCP+seriell');
echo $fail ? "\nFEHLER\n" : "\nAlle Tests bestanden\n";
