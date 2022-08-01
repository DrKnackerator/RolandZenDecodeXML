<?php

/**
 * Simple text/html table generator
 */
class TableGen {

    var $title=null;
    var $footer=null;
    var $hasHeader=true;
    var $colClass=[];
    var $tableClass="";

    var $data=[[]];

    // 'cursor'
    var $row=0;

    function put() {
        $args = func_get_args();

        foreach($args as $arg) {
            $this->data[$this->row][]=$arg;
        }
    }

    function putNewline() {
        $args = func_get_args();

        if (count($this->data)>0)
            $this->data[++$this->row]=[];

        foreach($args as $arg) {
            $this->data[$this->row][]=$arg;
        }
    }

    function renderText($fp=null) {
        if ($fp==null)
            $fp = STDOUT;

        // calc max col size
        $colSize=[];
        foreach($this->data as $row) {
            for($i=0; $i<count($row); $i++ ) {
                if (isset($colSize[$i]))
                    $colSize[$i] = max($colSize[$i], mb_strlen($row[$i]));
                else
                    $colSize[$i] = mb_strlen($row[$i]);
            }
        }
        $totalColCount=count($colSize);

        $totalLength = array_sum($colSize) + (count($colSize)-1)*3 + 4;
        $seperator = "+".str_repeat('-',$totalLength-2)."+\r\n";

        fwrite($fp,$seperator);
        if ($this->title) {
            fwrite($fp,sprintf("| %-".($totalLength-4)."s |\r\n",$this->title));
            fwrite($fp,$seperator);
        }

        for($rowNum=0;$rowNum<count($this->data);$rowNum++) {
            $row=$this->data[$rowNum];
            $maxColForRow=count($row);
            $colArray=[];
            for($i=0;$i<$totalColCount;$i++) {
                if ($i>=$maxColForRow)
                    $cell="";
                else
                    $cell=$row[$i];

                $colArray[] = sprintf("%-{$colSize[$i]}s",$cell);
            }
            fwrite($fp,"| ".join(" | ",$colArray)." |\r\n");
            if ($rowNum==0 && $this->hasHeader)
                fwrite($fp,$seperator);
        }

        fwrite($fp,$seperator);
        if ($this->footer) {
            fwrite($fp,sprintf("| %-".($totalLength-4)."s |\r\n",$this->footer));
            fwrite($fp,$seperator);
        }
    }

    function renderHTML($fp=null) {
        if ($fp==null)
            $fp = STDOUT;

        // calc max cols
        $totalColCount=0;
        foreach($this->data as $row)
            $totalColCount = max($totalColCount,count($row));

        fwrite($fp,"<table class='{$this->tableClass}'>");
        if ($this->title)
            fwrite($fp,"<tr><th colspan='$totalColCount'>".htmlspecialchars($this->title)."</th></tr>");

        for($rowNum=0;$rowNum<count($this->data);$rowNum++) {
            $row=$this->data[$rowNum];
            $maxColForRow=count($row);
            $colArray=[];

            for($i=0;$i<$totalColCount;$i++) {
                if ($i>=$maxColForRow)
                    $cell="&nbsp";
                else
                    $cell=htmlspecialchars($row[$i]);

                $tag = ($rowNum==0 && $this->hasHeader) ? "th" : "td";
                $tag .= isset($this->colClass[$i]) ? " class='{$this->colClass[$i]}'" : "";

                $colArray[] = "<$tag>$cell</$tag>";
            }
            fwrite($fp,"<tr>".join("",$colArray)."</tr>\r\n");
        }

        if ($this->footer)
            fwrite($fp,"<tr><th colspan='$totalColCount'>".htmlspecialchars($this->footer)."</th></tr>");

        fwrite($fp,"</table>");
    }

}