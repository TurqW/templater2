    <?php
    /**
     * Templater Plugin: Based from the include plugin, like MediaWiki's template
     * Usage:
     * {{template>page}} for "page" in same namespace
     * {{template>:page}} for "page" in top namespace
     * {{template>namespace:page}} for "page" in namespace "namespace"
     * {{template>.namespace:page}} for "page" in subnamespace "namespace"
     * {{template>page#section}} for a section of "page"
     *
     * Replacers are handled in a simple key/value pair method.
     * {{template>page&key=val&key2=val&key3=val}}
     * 
     * Multiline replacers or replacers that should start or end with whitespace should be wrapped in quotation marks.
     *
     * Templates are wiki pages, with replacers being delimited like
     * @key1@ @key2@ @key3@
     * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
     * @author     Turq Whiteside, based on work by: Jonathan arkell <jonnay@jonnay.net>, Esther Brunner <esther@kaffeehaus.ch>, Vincent de Lau <vincent@delau.nl>, Ximin Luo <xl269@cam.ac.uk>
     * @version    0.1
     */
     
    define('BEGIN_REPLACE_DELIMITER', '@');
    define('END_REPLACE_DELIMITER', '@');
     
    if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
    if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
    require_once(DOKU_PLUGIN.'syntax.php');
     
    /**
     * All DokuWiki plugins to extend the parser/rendering mechanism
     * need to inherit from this class
     */
    class syntax_plugin_templater2 extends \dokuwiki\Extension\SyntaxPlugin {
        /**
         * return some info
         */
        function getInfo(){
            return array(
                'author' => 'Turq Whiteside (building on extensive previous work)',
                'email'  => 'tinman@mage.city',
                'date'   => '2009-03-21',
                'name'   => 'Templater2 Plugin',
                'desc'   => 'Displays a wiki page (or a section thereof) within another, with configured replacements',
                'url'    => 'http://www.dokuwiki.org/plugin:templater',
            );
        }
     
        /**
     
         * What kind of syntax are we?
         */
        function getType(){
            return 'container';
        }
     
        /**
         * Where to sort in?
         */
        function getSort(){
            return 302;
        }
     
        /**
         * Paragraph Type
         */
        function getPType(){
            return 'block';
        }
     
        /**
         * Connect pattern to lexer
         */
        function connectTo($mode) {
          $this->Lexer->addSpecialPattern("{{template>.+?}}",$mode,'plugin_templater2');
        }
     
        /**
         * Handle the match
         */
        function handle($match, $state, $pos, Doku_Handler $handler){
            global $ID;
            // TODO: instead of a dumb split, grab all names and all values
            // Names: /(?<=[|]).*?(?=[=])/ trim whitespace
            // Values: /((?<=[=])(?:(?=(\\?))\2.)+?(?=[&}\n]))|((["'])(?:(?=(\\?))\5[\s\S])+?\4)/
            // still trim whitespace *then* quotation marks
            $match = substr($match,11,-2);                          // strip markup
            $replacers = preg_split('/(?<!\\\\)\&/', $match);       // Get the replacers
            $wikipage = array_shift($replacers);
     
            $replacers = $this->_massageReplacers($replacers);
     
            $wikipage = preg_split('/\#/u',$wikipage,2);            // split hash from filename
            $parentpage = empty(self::$pagestack)? $ID: end(self::$pagestack);  // get correct namespace
            resolve_pageid(getNS($parentpage),$wikipage[0],$exists);        // resolve shortcuts
     
            // check for perrmission
            if (auth_quickaclcheck($wikipage[0]) < 1) return false;
     
            // TODO: consolidate footnotes
            return array($wikipage[0], $replacers, cleanID($wikipage[1]));
        }
     
        private static $pagestack = array(); // keep track of recursing template renderings
     
        /**
         * Create output
         * This is a refactoring candidate. Needs to be a little clearer.
         */
        function render($mode, Doku_Renderer $renderer, $data) {
            if($mode == 'xhtml'){
                if ($data[0] === false) {
                    // False means no permissions
                    $renderer->doc .= '<div class="template"> No permissions to view the template </div>';
                    $renderer->info['cache'] = FALSE;
                    return true;
                }
     
                $file = wikiFN($data[0]);
                if (!@file_exists($file))
                {
                    $renderer->doc .= '<div class="templater">';
                    $renderer->doc .= "Template {$data[0]} not found. ";
                    $renderer->internalLink($data[0], '[Click here to create it]');
                    $renderer->doc .= '</div>';
                    $renderer->info['cache'] = FALSE;
                    return true;
                } elseif (array_search($data[0], self::$pagestack) !== false) {
                    $renderer->doc .= '<div class="templater">';
                    $renderer->doc .= "Processing of template {$data[0]} stopped due to recursion. ";
                    $renderer->internalLink($data[0], '[Click here to edit it]');
                    $renderer->doc .= '</div>';
                    return true;
                }
                self::$pagestack[] = $data[0]; // push this onto the stack
     
                // Get the raw file, and parse it into its instructions. This could be cached... maybe.
                $rawFile = io_readfile($file);
                $rawFile = str_replace($data[1]['keys'], $data[1]['vals'], $rawFile);
     
                // replace unmatched substitutions with "" or use DEFAULT_STR from data arguments if exists.
                $left_overs = '/'.BEGIN_REPLACE_DELIMITER.'.*'.END_REPLACE_DELIMITER.'/';
     
                $def_key=array_search(BEGIN_REPLACE_DELIMITER."DEFAULT_STR".END_REPLACE_DELIMITER,$data[1]['keys']);
                if($def_key){ $DEFAULT_STR = $data[1]['vals'][$def_key];}
                else $DEFAULT_STR = "";                         

                $rawFile = preg_replace($left_overs,$DEFAULT_STR,$rawFile); 
                
                $instr = p_get_instructions($rawFile);
     
                // filter section if given
                if ($data[2]) $instr = $this->_getSection($data[2],$instr);
     
                // correct relative internal links and media
                $instr = $this->_correctRelNS($instr, $data[0]);
     
                // render the instructructions on the fly
                $text = p_render('xhtml',$instr,$info);
     
                // remove toc, section edit buttons and category tags
                $patterns = array('!<div class="toc">.*?(</div>\n</div>)!s',
                                  '#<!-- SECTION \[(\d*-\d*)\] -->#',
                                  '!<div class="category">.*?</div>!s');
                $replace  = array('','','');
                $text = preg_replace($patterns,$replace,$text);
                $text = $this->consolidate_footnotes($text, $renderer);
     
                // prevent caching to ensure the included page is always fresh
                $renderer->info['cache'] = FALSE;
     
                // embed the included page
                $renderer->doc .= '<div class="templater">';
                $renderer->doc .= $text;
                $renderer->doc .= '</div>';
     
                array_pop(self::$pagestack); // pop off the stack when done
                return true;
            }
            return false;
        }
     
        /**
         * Get a section including its subsections
         */
        function _getSection($title,$instructions){
            foreach ($instructions as $instruction){
                if ($instruction[0] == 'header'){
     
                    // found the right header
                    if (cleanID($instruction[1][0]) == $title){
                        $level = $instruction[1][1];
                        $i[] = $instruction;
     
                    // next header of the same level -> exit
                    } elseif ($instruction[1][1] == $level){
                        return $i;
                    }
     
                // add instructions from our section
                } elseif (isset($level)){
                    $i[] = $instruction;
                }
            }
            return $i;
        }
     
        /**
         * Corrects relative internal links and media
         */
        function _correctRelNS($instr,$incl){
            global $ID;
     
            // check if included page is in same namespace
            $iNS = getNS($incl);
            if (getNS($ID) == $iNS) return $instr;
     
            // convert internal links and media from relative to absolute
            $n = count($instr);
            for($i = 0; $i < $n; $i++){
                if (substr($instr[$i][0], 0, 8) == 'internal'){
     
                    // relative subnamespace
                    if ($instr[$i][1][0]{0} == '.'){
                        $instr[$i][1][0] = $iNS.':'.substr($instr[$i][1][0], 1);
     
                    // relative link
                    } elseif (strpos($instr[$i][1][0],':') === false) {
                        $instr[$i][1][0] = $iNS.':'.$instr[$i][1][0];
                    }
                }
            }
            return $instr;
        }
     
        /**
         * Handles the replacement array
         */
        function _massageReplacers($replacers)
            {
                    $r = array();
                    if (is_null($replacers)) {
                            $r['keys'] = null;
                		$r['vals'] = null;
                    } else if (is_string($replacers)) {
                            list ($k, $v) = explode('=', $replacers, 2);
                            $r['keys'] = BEGIN_REPLACE_DELIMITER.trim($k).END_REPLACE_DELIMITER;
                            $r['vals'] = trim(str_replace('\|','|',$v));
                    } else if (is_array($replacers)) {
                            foreach($replacers as $rep) {
                                    list ($k, $v) = explode('=', $rep, 2);
                                    $r['keys'][] = BEGIN_REPLACE_DELIMITER.trim($k).END_REPLACE_DELIMITER;
                                    $r['vals'][] = trim(trim(str_replace('\|','|',$v)), "\"");
                            }
                    } else {
                        // This is an assertion failure. We should NEVER get here.
                		$r['vals'] = null;
                    }
                    return $r;
            }

        /**
         * Find all footnotes in $text
         * Manually strip the foot part
         * decrement the renderer's footnote count by the number of found footnotes
         * use $renderer's footnote_open(), doc, footnote_close() to add them to the primary document
         * Strip the just-added duplicate footnote clickers from the primary document
         * Return the text, which is added to the doc elsewhere
         */
        function consolidate_footnotes($text, Doku_Renderer $renderer){
            $footnotes = array();

            $dom = new DomDocument();
            $dom->loadHTML($text);
            $xpath = new DOMXpath($dom);

            $footnoteContentRaw = $xpath->query("//div[@class='footnotes']/div[@class='fn']/div");

            // TODO: this doesn't handle duplicated footnotes within the template
            foreach ($footnoteContentRaw as $content) {
                $footnotes[] = $footnoteContentRaw[$key]->ownerDocument->saveHTML( $content );
            }

            if(count($footnotes) > 0) {
                // Strips out the footnote footers
                $text = preg_replace('!<div class="footnotes">.*?</div>.*</div>!s', '', $text);
                
                $doclen = strlen($renderer->doc);
                Doku_Renderer_xhtml::$fnid -= count($footnotes);

                foreach($footnotes as $content) {
                    // Create the footnotes directly in the main doc
                    $renderer->footnote_open();
                    $renderer->doc .= $content;
                    $renderer->footnote_close();
                    // Strip the just-added footnote references
                    $renderer->doc = substr($renderer->doc, 0, $doclen);
                }
            }

            return $text;
        }
    }
     
    ?>
