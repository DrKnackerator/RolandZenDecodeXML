# RolandZenDecodeXML Build 3
A tool to decode Roland editor XML files (initially Jupiter X/Xm and ZenCore) and generate JSON and a Javascript module with byte offsets (for files)
and SYSEX locations/length, with plain text and HTML output tables for easy reading.

The data generated here is going to work with the Roland Jupiter X/Xm, Fantom 6/7/8 and 06/07/08, Juno-X, Zenology files etc. Note although MC-101 and MC-707 do not support sysex, buried in their project data is ZCore tone information in binary format.

Copies of the current output are available in the `out` directory (minus padding entries). You will only need to download and run this if you want to change the configuration to import more items or use a different source.

If you are interested in pulling apart these files or project/sound files to create useful tools you could come and join us at [Discord](https://discord.gg/Kf7gEDFzfV)

Also available is a tool for peeking into the structure of SVZ and MC-707/101 PRJ files at [**ZenInspector**](https://unrelated-domainname.com/zeninspector/) which I update when I figure out new information.

## Setup

For the default `jupiter` config, you will need to copy the .xml files from the
Roland Jupiter X/Xm editor into the directory `jupiter_xml`.

- Copy all the files from `C:\Users\Public\Documents\Roland Editor Library\JUPITERprmdb` into `jupiter_xml`

## Usage

    php decode.php <config_name>

Where `<config_name>` is the filename of a JSON file in the config directory e.g. for the default supplied `jupiter` config:

    php decode.php jupiter

*(note, do not include the .json extension)*

## Output
In the `out` directory three files will be created:

- `<config_name>.json` the full data
- `<config_name>.js` the full data formatted as a module (so like above but starts with `export default`)
- `<config_name>.html` a reference in HTML
- `<config_name>.txt` a reference in text (note, parameter lists are truncated)

## Config files

See `config/jupiter.json` for a fully formed example.

Config information is in two chunks

    settings
    importXML

### config : `settings`
Configuration settings:

    "settings": {
        "includePadding":false,
        "textTableToConsole": false,
        "prettyJSON" : false
    },

- `includePadding` - the binary layout of the information has padding added in places, which is invisible to the SYSEX layout. Turning this to `true` will include entries for the padding. Mainly useful to check generation as otherwise the padding is included but not listed as an actual element, so might be difficult to spot.
- `textTableToConsole` - instead of outputting `<config_name>.txt` the text version of the table is output to the console. Useful for checking/debugging.
- `prettyJSON` - Will pretty print the JSON and JS output. Not recommended as it makes the file huge.

### config : `importXML`

An array of objects listing the file to import from, and items to import

    "importXML": [ {
        "file":"jupiter_xml/db_muse_pcmex.xml",
        "blocks":[
            "PCMT_CMN",
            "PCMS_PMT",
            ...
            ]
        }, {
        "file": "jupiter_xml/db_bmc0.xml",
        "blocks": [
           "TONECOM"
            ],
        "groups": [
           "PCMEX"
            ]
        }
    ]

The list of names within `blocks` corresponds to a block entry in the XML file:

    <baseblock name="PCMS_PMT" desc="PCMSynth PMT">
	    <param id="STRUCT12" init="0" min="0" max="4" desc="Structure1-2" .../>
        ...

Whilst `groups` corresponds to an entry like this:

    <group name="PCMEX">
	    <block id="common"			baseblock="PCMT_CMN"						size="00.01.00" />
        ...

Groups are containers for blocks and some of these may have multiple copies (i.e. one for each partial).

## JSON Output
Simple example, range of values
```
{
"id": "PHRASE_VEL_SHIFT",
"description": "Phrase Velo Shift",
"byteOffset": 24,                       // byte offset from start of block
"lengthBytes": 1,                       // length of binary data block
"sysexOffset": 23,                      // sysex offset from start of block (decimal)
"lengthSysex": 2,                       // length of sysex data. more than 1 = use lower nibbles
"dataRange": [                          // range of data 
    -100,
    100
],
"initValue": 0,                         // initial value
"sysexValueOffset": 128,                // offset for binary/sysex value. so 0 as real data is encoded 
                                        // as 128 in sysex. Binary values are signed. 
"isPadding": false,                     // padding = no useful value, only enabled by option
"values": null                          // map of value => description
}
```
Example with set values
```
{
"id": "ctrlSrc1",
"description": "MFX CtrlSrc 1",
"byteOffset": 4,
"lengthBytes": 1,
"sysexOffset": 4,
"lengthSysex": 1,
"dataRange": [
    0,
    100
],
"initValue": 0,
"sysexValueOffset": 0,
"isPadding": false,
"values": {                           // map of value => description
    "0": "OFF",
    "1": "MOD:CC01",
    "2": "BRETH:CC02",
    "3": "CC03",
    "4": "FOOT:CC04",
    "5": "PTIME:CC05",
    "6": "DENT:CC06",
    ...
]
}
```
Some parameters can have a measurement name attached (such as db or cent) and also there is scaling applied to the original data value:
```
{
"id": "RELEASE",
"description": "Comp Release Time",
"byteOffset": 2,
"lengthBytes": 1,
"sysexOffset": 2,
"lengthSysex": 1,
"dataRange": [
    0,
    99
],
"initValue": 0,
"sysexValueOffset": 0,
"isPadding": false,
"values": null,
"displayMeasurement": "ms",         // 
"displayRange": [                   // scale dataRange to displayRange
    "10",
    "1000"
]
}
```

## Changelog
**Build3**

Added `displayMeasurement` and `displayRange`. Fixes invalid item issues with EQ section.
Added ModelSyn and PCM Rythm structures to output, plus some model data.
Changed `valueOffset` to `sysexValueOffset` as it only influences data sent by sysex.

## Limitations

Doesn't fully/correctly process all the xml files, not sure how it all fits together as a whole, so just picking the parts I know I need right now.

Currently doesn't not process `<alternate>` sections for union types (e.g. MFX or Model)
