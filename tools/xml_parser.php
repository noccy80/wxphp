<?php
/**
 * @author Jefferson González
 * 
 * @license 
 * This file is part of wxPHP check the LICENSE file for information.
 * 
 * @description
 * Script that parses the xml files generated by the doxygen tool for
 * the wxWidgets library in order to generate json files that serve as
 * input for the code generator of wxPHP
 * 
*/

//Disable anoying warnings and notices
error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);

//Change to correct working directory to be able to execute the script from everywhere
if($argv[0] == $_SERVER["SCRIPT_NAME"])
{
	chdir(str_replace("xml_parser.php" , "", $argv[0]));
}
else
{
	chdir(getcwd() . "/" . str_replace($_SERVER["SCRIPT_NAME"] , "", $_SERVER["PHP_SELF"]));
}

/* Types of compound on the index.xml files: 
 * 
 * class
 * struct
 * union
 * file
 * group
 * page
 * dir
 * 
*/

//Store elements for code generator
$includes = array();
$classes = array();
$class_variables = array();
$class_groups = array();
$structs = array();
$enums = array();
$defines = array();
$typedef = array();
$functions = array();
$global_variables = array();

//Store not handled kinds for study and debugging
$compund_not_handle = array();
$class_not_handle = array();
$file_not_handle = array();

//Store base classes to check which ones aren't documented
$base_classes = array();

/**
 * Indents a flat JSON string to make it more human-readable.
 * URL: http://recursive-design.com/blog/2008/03/11/format-json-with-php/
 *
 * @param string $json The original JSON string to process.
 *
 * @return string Indented version of the original JSON string.
 */
function json_indent($json) {

    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = "\t";
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i=0; $i<=$strLen; $i++) {

        // Grab the next character in the string.
        $char = substr($json, $i, 1);

        // Are we inside a quoted string?
        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;
        
        // If this character is the end of an element, 
        // output a new line and indent the next line.
        } else if(($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos --;
            for ($j=0; $j<$pos; $j++) {
                $result .= $indentStr;
            }
        }
        
        // Add the character to the result string.
        $result .= $char;

        // If the last character was the beginning of an element, 
        // output a new line and indent the next line.
        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos ++;
            }
            
            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }
        
        $prevChar = $char;
    }

    return $result;
}

function serialize_json($data)
{
	$data = json_encode($data);
	
	return json_indent($data);
}

$doc = new DOMDocument();
$doc->load("./../xml/index.xml");
$xpath = new DOMXPath($doc);

$entries = $xpath->evaluate("//compound[@kind]", $doc);
for ($i = 0; $i < $entries->length; $i++) 
{
	$kind = $entries->item($i)->getAttribute("kind");
	$refid = $entries->item($i)->getAttribute("refid");
	$name = $entries->item($i)->childNodes->item(0)->nodeValue;
	
	if($kind == "class" || $kind == "struct")
	{
		$classes[$name] = array();
		
		if($kind == "struct")
		{
			$classes[$name]["_struct"] = true;
			$structs[$name] = true;
		}
		
		$class_doc = new DOMDocument();
		$class_doc->load("./../xml/$refid.xml");
		
		$class_xpath = new DOMXPath($class_doc);
		
		//Check from which classes this one inherits
		$class_inherits = $class_xpath->evaluate("//inheritancegraph", $class_doc);
		
		//Save include file
		$includes[$class_xpath->evaluate("//includes", $class_doc)->item(0)->nodeValue]++;
		
		if($class_inherits->length > 0)
		{
			$class_inherit_nodes = $class_xpath->evaluate("node", $class_inherits->item(0));
			for($node=0; $node<$class_inherit_nodes->length; $node++)
			{
				if($class_inherit_nodes->item($node)->childNodes->item(1)->nodeValue == $name) 
				{
					$class_inherit_childnodes = $class_xpath->evaluate("childnode", $class_inherit_nodes->item($node));
					
					if($class_inherit_nodes->length > 0)
					{
						for($childnode=0; $childnode<$class_inherit_childnodes->length; $childnode++)
						{
							$parent_class_id = $class_inherit_childnodes->item($childnode)->attributes->getNamedItem("refid")->value;
							$parent_class_node = $class_xpath->evaluate('//node[@id="'.$parent_class_id.'"]', $class_doc);
							
							if($parent_class_node->length > 0)
							{
								$parent_class_name = $parent_class_node->item(0)->childNodes->item(1)->nodeValue;
								$classes[$name]["_implements"][] = $parent_class_name;
							}
						}
					}
					
					break;
				}
			}
		}
		
		//If class is implemented only on some platforms we store them
		$class_availability = $class_xpath->evaluate("/doxygen/compounddef/detaileddescription/para/onlyfor", $class_doc);
		if($class_availability->length > 0)
		{
			$classes[$name]["_platforms"] = explode(",", $class_availability->item(0)->nodeValue);
		}
		
		//Get the member functions of the class
		$class_member = $class_xpath->evaluate("//memberdef", $class_doc);
		for($member=0; $member<$class_member->length; $member++)
		{
			
			//Class functions
			if($class_member->item($member)->getAttribute("kind") == "function")
			{
				$function_name = $class_xpath->evaluate("name", $class_member->item($member))->item(0)->nodeValue;
				
				//If method is implemented only on some platforms we store them
				$platforms = false;
				$member_availability = $class_xpath->evaluate("detaileddescription/para/onlyfor", $class_member->item($member));
				if($member_availability->length > 0)
				{
					$platforms = explode(",", $member_availability->item(0)->nodeValue);
				}
				
				//skip destructor
				if($function_name{0} == "~")
					continue;
					
				$function_constant = false;
				$function_static = false;
				$function_virtual = false;
				$function_pure_virtual = false;
				$function_protected = false;
				
				//Check if member is constant
				if($class_member->item($member)->getAttribute("const") == "yes")
					$function_constant = true;
				
				//Check if member is static
				if($class_member->item($member)->getAttribute("static") == "yes")
					$function_static = true;
					
				//Check if member is virtual
				if($class_member->item($member)->getAttribute("virt") == "virtual")
					$function_virtual = true;
					
				//Check if member is pure virtual
				if($class_member->item($member)->getAttribute("virt") == "pure-virtual")
					$function_pure_virtual = true;
					
				if($class_member->item($member)->getAttribute("prot") == "protected")
					$function_protected = true;
					
				//Retrieve member type
				$function_type = str_replace(array(" *", " &"), array("*", "&"), $class_xpath->evaluate("type", $class_member->item($member))->item(0)->nodeValue);
				
				//Store type base class to later check which bases classes aren't documented
				if("" . stristr($function_type, "Base") . "" != "")
				{
					$base_classes[str_replace(array("&", " ", "*", "const"), "", $function_type)] = 1;
				}
				
				//Initialize arrays that will store parameters
				$parameters_type = array();
				$parameters_is_array = array();
				$parameters_extra = array();
				$parameters_name = array();
				$parameters_required = array();
				$parameters_values = array();
				
				//Check all member parameters
				$function_parameters = $class_xpath->evaluate("param", $class_member->item($member));
				if($function_parameters->length > 0)
				{
					for($parameter=0; $parameter<$function_parameters->length; $parameter++)
					{
						$parameters_type[] =  str_replace(array(" *", " &"), array("*", "&"), $class_xpath->evaluate("type", $function_parameters->item($parameter))->item(0)->nodeValue);
						
						$parameters_name[] =  $class_xpath->evaluate("declname", $function_parameters->item($parameter))->item(0)->nodeValue;
						
						//Check if parameter is array
						if($class_xpath->evaluate("array", $function_parameters->item($parameter))->length > 0)
						{
							$array_value = $class_xpath->evaluate("array", $function_parameters->item($parameter))->item(0)->nodeValue;
							
							if($array_value == "[]")
							{
								$parameters_is_array[] = true;
								$parameters_extra[] = false;
							}
							else
							{
								//TODO: Handle non bracket array elements
								$parameters_is_array[] = false;
								$parameters_extra[] = $array_value;
							}	
						}
						else
						{
							$parameters_is_array[] = false;
							$parameters_extra[] = false;
						}
						
						//Store type base class to later check which bases classes aren't documented
						$the_type = str_replace(array(" *", " &"), array("*", "&"), $class_xpath->evaluate("type", $function_parameters->item($parameter))->item(0)->nodeValue);
						if("" . stristr($the_type, "Base") . "" != "")
						{
							$base_classes[str_replace(array("&", " ", "*", "const"), "", $the_type)] = 1;
						}
						
						if($class_xpath->evaluate("defval", $function_parameters->item($parameter))->length > 0)
						{
							$parameters_values[] = $class_xpath->evaluate("defval", $function_parameters->item($parameter))->item(0)->nodeValue;
						}
						else
						{
							$parameters_required[] = true;
							$parameters_values[] = null;
						}
					}
				}
				
				if($platforms)
				{
					$classes[$name][$function_name][] = array("return_type"=>$function_type,
					"constant"=>$function_constant, "virtual"=>$function_virtual, "pure_virtual"=>$function_pure_virtual, 
					"static"=>$function_static, "protected"=>$function_protected, "parameters_type"=>$parameters_type, 
					"parameters_is_array"=>$parameters_is_array, "parameters_extra"=>$parameters_extra, "parameters_name"=>$parameters_name, 
					"parameters_required"=>$parameters_required, "parameters_default_value"=>$parameters_values, "platforms"=>$platforms);
				}
				else
				{
					$classes[$name][$function_name][] = array("return_type"=>$function_type,
					"constant"=>$function_constant, "virtual"=>$function_virtual, "pure_virtual"=>$function_pure_virtual, 
					"static"=>$function_static, "protected"=>$function_protected, "parameters_type"=>$parameters_type, 
					"parameters_is_array"=>$parameters_is_array, "parameters_extra"=>$parameters_extra, "parameters_name"=>$parameters_name, 
					"parameters_required"=>$parameters_required, "parameters_default_value"=>$parameters_values);
				}
			}
			
			//Class enumerations
			elseif($class_member->item($member)->getAttribute("kind") == "enum")
			{
				$enum_name = $class_xpath->evaluate("name", $class_member->item($member))->item(0)->nodeValue;
				
				//Skip badly referenced enums
				if($enum_name{0} == "@")
					continue;
				
				if(!isset($enums[0][$name]))
					$enums[0][$name] = array();
				
				$enums[0][$name][$enum_name] = array();
				
				$enum_values = $class_xpath->evaluate("enumvalue", $class_member->item($member));
				
				for($enum_value=0; $enum_value<$enum_values->length; $enum_value++)
				{
					$enums[0][$name][$enum_name][] = $class_xpath->evaluate("name", $enum_values->item($enum_value))->item(0)->nodeValue;
				}
			}
			//Class variables
			elseif($class_member->item($member)->getAttribute("kind") == "variable")
			{
				$variable_type = $class_xpath->evaluate("type", $class_member->item($member))->item(0)->nodeValue;
				$variable_name = $class_xpath->evaluate("name", $class_member->item($member))->item(0)->nodeValue;
				
				$variable_static = false;
				$variable_mutable = false;
				$variable_protected = false;
				$variable_public = false;
				
				if($class_member->item($member)->getAttribute("static") != "no")
					$variable_static = true;
					
				if($class_member->item($member)->getAttribute("mutable") != "no")
					$variable_mutable = true;
				
				if($class_member->item($member)->getAttribute("prot") == "protected")
					$variable_protected = true;
					
				if($class_member->item($member)->getAttribute("prot") == "public")
					$variable_public = true;
				
				
				
				$class_variables[$name][$variable_name] = array(
					"type"=>$variable_type, "static"=>$variable_static,
					"mutable"=>$variable_mutable, "protected"=>$variable_protected,
					"public"=>$variable_public
				);
			}
			//Store not handled members of the class
			else
			{
				$class_not_handle[$class_member->item($member)->getAttribute("kind")]++;
			}
		}
	}
	elseif($kind == "file")
	{
		//Skip non portable class files
		if($refid == "accel_8h")
		{
			continue;
		}
		
		$file_doc = new DOMDocument();
		$file_doc->load("./../xml/$refid.xml");
		
		$file_xpath = new DOMXPath($file_doc);
		
		$file_members = $file_xpath->evaluate("//memberdef", $file_doc);
		
		//Save include file
		//$includes[$file_xpath->evaluate("//compoundname", $file_doc)->item(0)->nodeValue]++;
		
		for($member=0; $member<$file_members->length; $member++)
		{	
			//Store file constant macro definitions
			if($file_members->item($member)->getAttribute("kind") == "define")
			{
				$define_name = $file_xpath->evaluate("name", $file_members->item($member))->item(0)->nodeValue;
				$define_initializer = $file_xpath->evaluate("initializer", $file_members->item($member))->item(0)->nodeValue;
				
				//Skip macro function defines
				if($file_xpath->evaluate("param", $file_members->item($member))->length > 0)
				{
					continue;
				}
				
				//Skip defines used for compiler
				if($define_name{0} == "_" && $define_name{1} == "_")
				{
					continue;
				}
				
				$defines[$define_name] = "$define_initializer";
			}
			
			//Store global enumerations
			elseif($file_members->item($member)->getAttribute("kind") == "enum")
			{
				$enum_name = $file_xpath->evaluate("name", $file_members->item($member))->item(0)->nodeValue;
				
				//Skip badly referenced enums
				if($enum_name{0} == "@")
					continue;
				
				if(!isset($enums[1]))
					$enums[1] = array();
				
				$enums[1][$enum_name] = array();
				
				$enum_values = $file_xpath->evaluate("enumvalue", $file_members->item($member));
				
				for($enum_value=0; $enum_value<$enum_values->length; $enum_value++)
				{
					$enums[1][$enum_name][] = $file_xpath->evaluate("name", $enum_values->item($enum_value))->item(0)->nodeValue;
				}
			}
			
			//Store functions
			elseif($file_members->item($member)->getAttribute("kind") == "function")
			{
				if($file_members->item($member)->getAttribute("kind") == "function")
				{
					$function_name = $file_xpath->evaluate("name", $file_members->item($member))->item(0)->nodeValue;
					
					if($function_name{0} == "w" && $function_name{1} == "x")
					{
						$function_type = $file_xpath->evaluate("type", $file_members->item($member))->item(0)->nodeValue;
						$function_type = str_replace(array(" *", " &"), array("*", "&"), $function_type);
						
						//Check all function parameters
						$function_parameters = $file_xpath->evaluate("param", $file_members->item($member));
						
						$parameters_type = array();
						$parameters_is_array = array();
						$parameters_extra = array();
						$parameters_name = array();
						$parameters_required = array();
						$parameters_value = array();
						
						for($function_parameter=0; $function_parameter<$function_parameters->length; $function_parameter++)
						{
							$parameters_type[] = str_replace(array(" *", " &"), array("*", "&"), $file_xpath->evaluate("type", $function_parameters->item($function_parameter))->item(0)->nodeValue);
							$parameters_name[] = $file_xpath->evaluate("declname", $function_parameters->item($function_parameter))->item(0)->nodeValue;
							
							//Check if parameter is array
							if($file_xpath->evaluate("array", $function_parameters->item($function_parameter))->length > 0)
							{
								$array_value = $file_xpath->evaluate("array", $function_parameters->item($function_parameter))->item(0)->nodeValue;
								
								if($array_value == "[]")
								{
									$parameters_is_array[] = true;
									$parameters_extra[] = false;
								}
								else
								{
									//Handle non bracket array elements
									$parameters_is_array[] = false;
									$parameters_extra[] = $array_value;
								}	
							}
							else
							{
								$parameters_is_array[] = false;
								$parameters_extra[] = false;
							}
							
							if($file_xpath->evaluate("defval", $function_parameters->item($function_parameter))->length > 0)
							{
								$parameters_value[] = $file_xpath->evaluate("defval", $function_parameters->item($function_parameter))->item(0)->nodeValue;
							}
							else
							{
								$parameters_required[] = true;
								$parameters_value[] = null;
							}
						}
						
						$functions[$function_name][] = array("return_type"=>$function_type,
						"parameters_type"=>$parameters_type, "parameters_is_array"=>$parameters_is_array, 
						"parameters_extra"=>$parameters_extra, "parameters_name"=>$parameters_name, 
						"parameters_required"=>$parameters_required, "parameters_default_value"=>$parameters_values);
					}
				}
			}
			
			//Store type definitions definitions
			elseif($file_members->item($member)->getAttribute("kind") == "typedef")
			{
				$typedef_name = $file_xpath->evaluate("name", $file_members->item($member))->item(0)->nodeValue;
				$typedef_type = $file_xpath->evaluate("type", $file_members->item($member))->item(0)->nodeValue;
				
				$typedef[$typedef_name] = $typedef_type;
			}
			
			//Store global variables
			elseif($file_members->item($member)->getAttribute("kind") == "variable")
			{
				$global_variable_name = $file_xpath->evaluate("name", $file_members->item($member))->item(0)->nodeValue;
				$global_variable_type = $file_xpath->evaluate("type", $file_members->item($member))->item(0)->nodeValue;
				$global_variables [$global_variable_name] = str_replace(array(" *", " &"), array("*", "&"), $global_variable_type);
			}
			
			//Store kinds not handle on the files
			else
			{
				$file_not_handle[$file_members->item($member)->getAttribute("kind")]++;
			}
		}
	}
	else if($kind == "group")
	{
		//Skip none class groups
		if("".strpos($refid, "class")."" == "")
			continue;
			
		$group_doc = new DOMDocument();
		$group_doc->load("./../xml/$refid.xml");
		
		$group_xpath = new DOMXPath($group_doc);
		
		$group_name = $group_xpath->evaluate("//compoundname", $group_doc)->item(0)->nodeValue;
		
		$group_array = array();
		
		//Get the member functions of the class
		$group_members = $group_xpath->evaluate("//innerclass", $group_doc);
		for($member=0; $member<$group_members->length; $member++)
		{
			$group_array[] = $group_members->item($member)->nodeValue;
		}
		
		$class_groups[$group_name] = $group_array;
	}
	
	//Store compound kinds on index.xml not handled
	else
	{
		$compound_not_handle[$kind]++;
	}
}

print "\nBase classes not documented:\n\n";
foreach($base_classes as $name=>$value)
{
	//TODO: Should we provide by default forward declarations for this
	//classes
	if("".stristr($name, "::")."" == "")
	{
		if(!isset($classes[$name]))
			print "    " . $name . "\n";
	}
}
print "\n";

print "Class kinds not handled:\n";
print_r($class_not_handle);
print "\n";

print "Compound kinds not handled:\n";
print_r($compound_not_handle);
print "\n";

print "File kinds not handled:\n";
print_r($file_not_handle);
print "\n";

print "Include files found: " . count($includes) . "\n";
print "Define constants found: " . count($defines) . "\n";
print "Type definitions found: " . count($typedef) . "\n";
print "Global variables found: " . count($global_variables) . "\n";
print "Classes found: " . (count($classes)-count($structs)) . "\n";
print "Classes with variables found: " . count($class_variables) . "\n";
print "Class groups found: " . count($class_groups) . "\n";
print "Structs found: " . count($structs) . "\n";
print "Functions found: " . count($functions) . "\n";
print "Class enumerations found: " . count($enums[0]) . "\n";
print "Global enumerations found: " . count($enums[1]) . "\n";

print "\n";
print "Saving output to files...\n";

//We save the output as indented json format to make possible manual editing
file_put_contents("./../json/includes.json", serialize_json($includes));
file_put_contents("./../json/classes.json", serialize_json($classes));
file_put_contents("./../json/class_variables.json", serialize_json($class_variables));
file_put_contents("./../json/class_groups.json", serialize_json($class_groups));
file_put_contents("./../json/functions.json", serialize_json($functions));
file_put_contents("./../json/enums.json", serialize_json($enums));
file_put_contents("./../json/consts.json", serialize_json($defines));
file_put_contents("./../json/typedef.json", serialize_json($typedef));
file_put_contents("./../json/global_variables.json", serialize_json($global_variables));

print "Done.\n"

?>