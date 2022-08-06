<?php
require_once("TableGen.php");

function err($text) {
    echo $text;
    return -1;
}

function pr($val) {
    print_r($val);
    echo "\r\n";
}
// return array of hex values in sysex format, i.e. 7 bits
function getSysexValueArray($value, $length=2) {
    $rv=[];
    // generate little endian
    for($i=0;$i<$length;$i++) {
        $rv[] = substr("00".dechex($value & 0x7f),-2);
        $value>>=7;
    }
    return array_reverse($rv);       // to big endian
}

// string of sysex hex to decimal
function convertSysexValue($string) {
    $parts = preg_split('/ |\./',$string);
    if (count($parts)==0)
        return;
    $parts = array_reverse($parts); // low little endian
    $total=0;
    for ($i=0;$i<count($parts);$i++)
        $total += hexdec($parts[$i]) * pow(128,$i);
    return $total;
}


class NumDefs {
    static array $table=[];

    static function clear() {
        NumDefs::$table=[];
    }

    static function set( String $name, String $value) {
        if (isset(NumDefs::$table[$name]) && NumDefs::$table[$name]!=$value)
            throw new Exception(sprintf("NumDef %s is being set to a different value was %d now %d",$name,NumDefs::$table[$name],$value));
        NumDefs::$table[$name] = $value;
    }

    static function get( String $name) {
        if (!isset(NumDefs::$table[$name]))
            throw new Exception("missing numdef '$name'");
        return NumDefs::$table[$name];
    }

    public static function numberOrNumDef($val): int {
        $val = (string)$val; // val is an attribute object
        if (is_numeric($val))
            return intval($val);
        return NumDefs::get($val);
    }

}

class DataEntry {
    var $id;
    var $description;
    var $byteOffset;
    var $lengthBytes=1;
    var $sysexOffset;
    var $lengthSysex=1;
    var $dataRange;
    var $initValue;
    var $valueOffset;
    var bool $isPadding=false;
    var $values=null;


    function __construct($id, $byteOffset, $sysexOffset, $lengthBytes, $lengthSysex, $description, $dataRange, $init, $valueOffset, $valuesString="", $padding=false) {
        $this->id = $id;
        $this->byteOffset = $byteOffset;
        $this->sysexOffset = $sysexOffset;
        $this->lengthBytes = $lengthBytes;
        $this->lengthSysex = $lengthSysex;
        $this->description = $description;
        $this->dataRange = $dataRange;
        $this->initValue = $init;
        $this->valueOffset = $valueOffset;
        $this->processValueString($valuesString);
        $this->isPadding = $padding;
    }

    function processValueString($valuesString) {
        $valuesString = trim($valuesString);
        if (empty($valuesString))
            return;
        if ($valuesString=='L64 - 63R')
            return;

        // look for displayMeasurement
        if ( preg_match('/(.*?) ?(\[(.*)])?$/m', $valuesString, $matches ) && !empty($matches[3] )) {
            $this->displayMeasurement=$matches[3];
            $valuesString =  $matches[1];
        }

        // look for numeric range (optional measurement)
        if (preg_match('/^(-?\d+\.?\d*) ?- ?(\+?\d+\.?\d*+)/m',$valuesString,$matches)) {
            // range same as datarange
            if (strstr($valuesString,'cent')!==false)
                pr($matches);
            if ($matches[1]==$this->dataRange[0] && $matches[2]==$this->dataRange[1])
                return;
            $this->displayRange=[$matches[1],$matches[2]];

            return;
        }

        // must be list
        $this->values=(object) preg_split('/, ?/',$valuesString);
    }

    function outTable(TableGen $table, $truncate=false) {
        $vs = "";
        if ($this->values) {
            if ($truncate) {
                $vs = join(", ", array_slice((array)$this->values, 0, 10));
                if (count((array)$this->values) > 10)
                    $vs .= " ...";
            }
            else
                $vs = join(", ", (array)$this->values);
        }

        $syOffset = getSysexValueArray($this->sysexOffset);
        $table->putNewline($this->id,
                           $this->description,
                           sprintf(" %s %s - %02x", $syOffset[0], $syOffset[1], $this->lengthSysex ),
                           sprintf("0x%04x %04d - %02d",$this->byteOffset, $this->byteOffset, $this->lengthBytes ),
                           $this->dataRange[0],
                           $this->dataRange[1],
                           $this->valueOffset,
                           $this->initValue,
                           $vs
        );
    }
}
class DataStructure {

    var $entries=[];
    var $byteOffset=0;
    var $sysexOffset=0;
    var $name;
    var $paddingNum=1;
    var $includePadding=true;

    //subblock
    var $subBlockPrefix="";
    var $subBlockIDPrefix="";

    function __construct($name,$includePadding) {
        $this->name = $name;
        $this->includePadding=$includePadding;
    }

    function processBaseBlock(SimpleXMLElement $parent) {
        foreach($parent->children() as $el) {
            $this->processElement($el);
        }
    }

    function processElement( SimpleXMLElement $el ) {
        $attr=$el->attributes();
        $name = $el->getName();

        switch($name) {
            case 'param':
                $this->createDataFromParam($el);
                break;
            case 'padding':
                $this->createPaddingFromParam($el);
                break;
            case 'subblock':
                $this->processSubblock($el);
                break;
            case 'common':
            case 'share':
                $this->processChildren($el);
                break;
            case 'alternate':
                break;
            default:
                throw new Exception("Unknown element $name");
        }
    }

    function processSubblock(SimpleXMLElement $el) {
        if ($this->subBlockPrefix!="")
            return err("Nested subblock in {$this->name}");

        $attr=$el->attributes();
        $prefix = $attr['desc'];
        if ($prefix=="")
            $prefix = $attr['id'];
        $arraySize = intval($attr['array']);
        for($i=1;$i<=$arraySize;$i++) {
            pr("  - sub block $i");
            foreach($el->children() as $child) {
                $this->subBlockPrefix = $prefix.' '.$i.' ';
                $this->subBlockIDPrefix = $prefix.'_'.$i.'_';
                $this->processElement($child);
            }
        }
        $this->subBlockPrefix = "";
        $this->subBlockIDPrefix = "";
    }

    function createDataFromParam(SimpleXMLElement $el) {
        $attr=$el->attributes();
        // calc range i.e. byte size
        $dataRange = [NumDefs::numberOrNumDef($attr['min']), NumDefs::numberOrNumDef($attr['max'])];
        $range = $dataRange[1]-$dataRange[0];
        $sysexSize=1;$byteSize=1;

        if ( ($attr['type']??'') !='' ) {
            if (strstr($attr['type'],'16')!==false) {
                $sysexSize=4;$byteSize=2;
            } else
                $sysexSize=2;
        } else {
            if ($range > 127 && $range <= 255) {
                $sysexSize = 2;
            } else if ($range >= 256) {
                $sysexSize = 4;
                $byteSize = 2;
            }
        }

        $idPost="";$descPost="";
        $itemCount=1;
        $isArray=false;
        if (($attr['array']??0) >1 ) {
            $itemCount = intval($attr['array']);
            $isArray=true;
        }

        for($i=1; $i<=$itemCount;$i++) {
            $desc = $attr['desc'];
            if ($isArray) {
                $idPost = "_$i";
                if ( strstr( $attr['desc'],'$')===false)
                    $descPost= " $i";
                else {
                    $desc = str_replace('$', $i, $attr['desc']);
                }
            }

            $d = new DataEntry($this->subBlockIDPrefix.$attr['id'].$idPost,
                               $this->byteOffset,
                               $this->sysexOffset,
                               $byteSize,
                               $sysexSize,
                               $this->subBlockPrefix . $desc.$descPost,
                               $dataRange,
                               intval($attr['init']),
                               intval($attr['sysex_ofst'] ?? 0),
                                (string) $attr['desc_val'],
                               false
            );
            $this->entries[] = $d;

            $this->byteOffset += $byteSize;
            $this->sysexOffset += $sysexSize;
        }
    }

    function createPaddingFromParam(SimpleXMLElement $el) {
        $attr=$el->attributes();
        $byteSize=intval($attr['bytesize']);

        if ($this->includePadding) {
            $d = new DataEntry($this->subBlockIDPrefix . "PADDING" . $this->paddingNum++,
                               $this->byteOffset,
                               0,
                               $byteSize,
                               0,
                               "__padding",
                               [0, 0],
                               null,
                               null,
                               null,
                               true
            );
            $this->entries[] = $d;
        }

        $this->byteOffset+=$byteSize;
        // no Sysex padding
    }

    function processChildren( SimpleXMLElement $parent) {
        foreach($parent->children() as $el) {
            $this->processElement($el);
        }
    }

    function outTable($fp,$truncate=false,$html=false) {
        $table = new TableGen();

        $syOffset = getSysexValueArray($this->sysexOffset);

        $table->tableClass="table table-bordered table-striped noWrap autoWidth";
        $table->title = "Block id:".$this->name;
        $table->footer = sprintf("Block id:{$this->name} : Total Length Sysex: %s %s Bytes: 0x%04x %d",$syOffset[0], $syOffset[1], $this->byteOffset, $this->byteOffset);
        $table->put("ID", "Description", "SXOff - len", "Byte offset - len", "Min", "Max", "Offset", "Init", "Values");
        $table->colClass=[4=>"right", 5=>"right",6=>"right",7=>"right",8=>"wrap"];

        foreach ($this->entries as $entry)
            $entry->outTable($table,$truncate);

        if ($html)
            $table->renderHTML($fp);
        else
            $table->renderText($fp);
    }

    // return a clone of this object designed for JSON output
    function getCloneForOutput() {
        return [
            "name"=>$this->name,
            "byteLength"=>$this->byteOffset,
            "sysexLength"=>$this->sysexOffset,
            "parameters"=>$this->entries
        ];
    }
}

class Group {
    var $name;
    var $byteOffset=0;
    var $sysexOffset=0;
    var $children=[];
    var $outputObject=null;

    public function __construct($name,$outputObject) {
        $this->name = $name;
        $this->outputObject = $outputObject;
    }

    public function processGroup(SimpleXmlElement $el) {
        foreach($el->children() as $child) {
            switch($child->getName()) {
                case 'block':
                    $this->processBlock($child);
                    break;
                case 'offset':
                    $this->processOffset($child);
                    break;
                default:
                    throw new Exception(sprintf("Unknown Tag Type '%s'",$child->getName()) );
            }
        }

        //return element
        return [ 'blocks'=>$this->children, 'totalByteLength'=>$this->byteOffset ];
    }

    // <block id="ptl"				baseblock="PCMT_PTL"	array="partialSize" size="00.01.00" />
    function processBlock(SimpleXMLElement $el) {
        $attrs = $el->attributes();
        $id=(string) $attrs['id'];
        $baseBlock=(string) $attrs['baseblock'];
        $sysexSize=(string) $attrs['size'];
        if ($sysexSize=="")
            $sysexSize=128;
        else
            $sysexSize = convertSysexValue($sysexSize);

        $array=1;
        if (!empty($attrs['array']))
            $array = NumDefs::numberOrNumDef($attrs['array']);

        $block = $this->outputObject['blocks'][$baseBlock]??null;
        if ($block==null)
            throw new Exception("Unable to find block named '$baseBlock'");

        $child = [ 'blockName'=>$baseBlock, 'count'=>$array, 'sysexOffset'=>[], 'byteOffset'=>[],
                   'blockByteLength'=> $block['byteLength'],'totalByteLength'=>$array*$block['byteLength'] ];

        for($i=0; $i<$array; $i++) {
            $child['byteOffset'][]=$this->byteOffset;
            $child['sysexOffset'][]=join( ' ', getSysexValueArray($this->sysexOffset,3) );

            $this->byteOffset += $block['byteLength'];
            $this->sysexOffset += $sysexSize;
        }
        $this->children[] = $child;
    }

    function processOffset(SimpleXMLElement $el) {
        $attrs = $el->attributes();
        $adrs=(string) $attrs['adrs'];
        if ($adrs=="")
            throw new Exception("Cannot find attribute adrs in <offset>");
        $this->sysexOffset = convertSysexValue($adrs);
    }

    function outTable($fp,$html=false) {
        $table = new TableGen();

        $table->tableClass="table table-bordered table-striped noWrap autoWidth";
        $table->title = "Group id:".$this->name;
        $table->footer = sprintf("Group id:{$this->name} : Total Length Bytes: 0x%04x %d", $this->byteOffset, $this->byteOffset);
        $table->put("Block", "Index", "Sysex Start", "Byte offset", "Block Byte Length","Total Byte Length");
        $table->colClass=[1=>"right", 2=>"right",3=>"right",4=>"right",5=>"right"];

        foreach($this->children as $block) {
            for ($i=0; $i<$block['count'];$i++) {
                if ($i==0)
                    $table->putNewline($block['blockName']);
                else
                    $table->putNewline("");

                $table->put($i+1, $block['sysexOffset'][$i], $block['byteOffset'][$i]);

                if ($i==0)
                    $table->put($block['blockByteLength'], $block['totalByteLength']);
            }
        }

        if ($html)
            $table->renderHTML($fp);
        else
            $table->renderText($fp);
    }

}

// -------------------------------------------------------------------------------
$configName = $argv[1] ?? "no config specified";

$outputObject=[
    "blocks"=>[],
    "groups"=>[]
];

pr("*********** Zen XML Converter Thingy ** config:$configName ***********\r\n");
$config = json_decode(file_get_contents("config/$configName.json"));
if ($config==null)
    throw new Exception("Invalid Config");

$conOut = $config->settings->textTableToConsole ?? false;
$includePadding = $config->settings->includePadding ?? false;

$textOutFile = $conOut ? STDOUT : fopen("out/$configName.txt", "w");
$JSONOutFile = fopen("out/$configName.json","w");
$JSOutFile = fopen("out/$configName.js","w");
$HTMLOutFile = fopen("out/$configName.html","w");
$tableTmp = tmpfile();
$HTMLTemplate = file_get_contents("template.html");

foreach($config->importXML as $xmlFileImport) {
    pr("---import xml - {$xmlFileImport->file}");
    $xmlFile = file_get_contents($xmlFileImport->file);
    $xml = simplexml_load_string($xmlFile);

    // numdefs
    $numDefs = $xml->xpath("/*/numdef");
    foreach($numDefs as $numDef) {
        $attrs = $numDef->attributes();
        NumDefs::set($attrs['name'],intval($attrs['num']));
    }

    // blocks
    foreach ($xmlFileImport->blocks as $blockName) {
        pr("  $blockName");
        $p = $xml->xpath("/*/*[@name='$blockName']");
        if ($p[0]==null)
            throw new Exception("Cannot find block $blockName");
        $d = new DataStructure($blockName,$includePadding);
        $d->processBaseBlock($p[0]);
        $d->outTable($textOutFile,true);
        $d->outTable($tableTmp,false,true);
        $outputObject['blocks'][$d->name]=$d->getCloneForOutput();
        fwrite($textOutFile,"\r\n");
    }

    // groups
    $start=0;
    foreach($xmlFileImport->groups??[] as $groupName) {
        pr("  ".$groupName);
        $p = $xml->xpath("/*/group[@name='$groupName']");

        $group = new Group($groupName, $outputObject);
        $groupEntry = $group->processGroup($p[0]);
        $group->outTable($textOutFile,false);
        $group->outTable($tableTmp,true);
        $outputObject['groups'][$groupName]=$groupEntry;
    }
}

fwrite($JSOutFile,"export default\r\n");

$JSONEncodeOptions = JSON_UNESCAPED_SLASHES;
if ($config->settings->prettyJSON ?? false)
    $JSONEncodeOptions |= JSON_PRETTY_PRINT;

$JSON = json_encode($outputObject,$JSONEncodeOptions);
fwrite($JSONOutFile, $JSON);
fwrite($JSOutFile, $JSON);

rewind($tableTmp);
$htmlTable = "<h1>Config: $configName</h1>".fread($tableTmp,2*1024*1024);
$HTML = str_replace("<REPLACE/>",$htmlTable,$HTMLTemplate);
fwrite($HTMLOutFile,$HTML);

fclose($JSOutFile);
fclose($JSONOutFile);
fclose($textOutFile);
fclose($HTMLOutFile);
fclose($tableTmp);