<?php
	if(!isset($_FILES) || empty($_FILES)){
?><!DOCTYPE html>
<html>
<head><title>WinSCP configuration file converter</title></head>
	<body>
		<h1>Convert your WinSCP site manager data to Filezilla format</h1>
		<form enctype="multipart/form-data" action="<?php echo $_SERVER['SCRIPT_NAME']?>" method="post">
			<input type="hidden" name="MAX_FILE_SIZE" value="1048576" />
			<label for="userfile">INI file : </label><input name="userfile" type="file" id="userfile" />
			<input type="submit" />
		</form>
	</body>
</html>
<?php 
		die;
	}
	
	$bErrorHasHappened = false;
	
	function processStructure($aStructureToParse, $oRootElement, $oParentElement){
		if(is_array($aStructureToParse)){
			foreach ($aStructureToParse as $sFolderName => $aSubStructure){
				// site names are prefixed by "s|"
				if(preg_match('/^s\|/', $sFolderName) == 1){
					// this is a <Site>, only append it to the parent <Folder>
					$sSiteName = preg_replace('/^s\|/', '', $sFolderName);
					if(gettype($aSubStructure) == 'object'){
						// This is a DOMElement, and not an array
						$oParentElement->appendChild($aSubStructure);
					}
				} else{
					// this is a <Folder>, create the <Folder> element, and parse the substructure
					$oCurrentFolder = $oRootElement->createElement('Folder', $sFolderName);
					$oParentElement->appendChild($oCurrentFolder);
					processStructure($aSubStructure, $oRootElement, $oCurrentFolder);
				}
			}
		}
	}
	
	// open the file, filter out non session-related data
	$handle = fopen($_FILES['userfile']['tmp_name'], "r");
	$sINIFile = '';
	if ($handle) {
		$bKeepSection = true;
		while (($line = fgets($handle)) !== false) {
			if (preg_match('/^\[/', $line)){
				// new section ; by default do not keep the following lines except it is session data
				if(preg_match('/^\[Sessions\\\\(.*)\\](\\s*)$/', $line, $aMatches)){
					$bKeepSection = true;
					// As mentioned in http://php.net/manual/en/function.parse-ini-string.php, 
					// some exotic characters in the section name can lead to errors 
					// while reading the file. Uncomment array keys if you have read errors.
					$aRemoveForbiddenSectionChars = Array(
						'?' => '',
						// '{' => '',
						// '}' => '',
						'|' => '',
						'&' => '',
						'~' => '',
						'!' => '',
						'[' => '',
						// '(' => '',
						// ')' => '',
						'^' => '',
						']' => '',
					);
					$line = '[Sessions\\' . strtr($aMatches[1], $aRemoveForbiddenSectionChars) . ']' . $aMatches[2];
					$sINIFile .= $line;
				}else{
					$bKeepSection = false;
				}
			}else{
				// process the line read. If it is site data, keep it.
				if($bKeepSection){
					$sINIFile .= $line;
				}
			}
		}

		fclose($handle);
	}
	
	$aINIFile = parse_ini_string($sINIFile, true, INI_SCANNER_RAW);
	
	$aDirectoryStructure = array();

	$oDoc = new DomDocument("1.0", "UTF-8");
	$oRootNode = $oDoc->createElement('FileZilla3');
	$oDoc->appendChild($oRootNode);
	$oServers = $oDoc->createElement('Servers');
	$oRootNode->appendChild($oServers);
	$oDoc->preserveWhiteSpace = false;
    $oDoc->formatOutput = true;
    $oDoc->standalone = true;
	
	if(!is_array($aINIFile) || empty($aINIFile)){
		$bErrorHasHappened = true;
		$sErrorToDisplay = "The attempt to read the .ini file failed.";
	}else{
		ksort($aINIFile);
		foreach ($aINIFile as $sSessionName => $aSessionConf){
			// 1 create structure from the session name
			$sSessionName = str_replace('Sessions\\','', $sSessionName);
			$aFoldersStructure = explode('/', $sSessionName);
			$aFoldersToCreate = array_splice($aFoldersStructure, 0, count($aFoldersStructure)-1);
			$sSessionLabel = urldecode($aFoldersStructure[count($aFoldersStructure) -1 ]);
			
			// If the HostName value is not found, do not add the site.
			if(!isset( $aSessionConf['HostName'])){
				continue;
			}
			$sHostName = $aSessionConf['HostName'];
			
			// 2 export conf, in the right structure
			$oServer = $oDoc->createElement('Server', $sSessionLabel);
			$oNodeToAdd = $oDoc->createElement('Host', $sHostName);
			$oServer->appendChild($oNodeToAdd);
			
			// Protocol 0 = FTP
			// Protocol 1 = SFTP
			$sProtocol = 1;	// by default, if the protocol is not set in WinSCP, it is SFTP
			if (isset($aSessionConf['FSProtocol']) && $aSessionConf['FSProtocol'] == 5){
				$sProtocol = "0";
			}
			$oNodeToAdd = $oDoc->createElement('Protocol', $sProtocol);
			$oServer->appendChild($oNodeToAdd);
			
			if(array_key_exists('UserName', $aSessionConf)){
				$sUserName = $aSessionConf['UserName'];
			}else{
				$sUserName = '';
			}
			if(array_key_exists('Password', $aSessionConf)){
				$sCurrentPassword = $aSessionConf['Password'];
			}else{
				$sCurrentPassword = '';
			}
			$oNodeToAdd = $oDoc->createElement('User', $sUserName);
			$oServer->appendChild($oNodeToAdd);
			
			$sPassword = base64_encode(exec('/usr/bin/java -jar ' .  __DIR__ . '/WinSCPPasswordDecrypt.jar "' . $sHostName . '" "' . $sUserName . '" "' . $sCurrentPassword . '"'));
			$oPass = $oDoc->createElement('Pass', $sPassword);
			$oPassEncoding = $oDoc->createAttribute('encoding');
			$oPassEncoding->value = 'base64';
			$oPass->appendChild($oPassEncoding);
			$oServer->appendChild($oPass);
			
			if(array_key_exists('LocalDirectory', $aSessionConf)){
				$oNodeToAdd = $oDoc->createElement('LocalDir', urldecode($aSessionConf['LocalDirectory']));
			}else{
				$oNodeToAdd = $oDoc->createElement('LocalDir', '');
			}
			$oServer->appendChild($oNodeToAdd);

			if(array_key_exists('RemoteDirectory', $aSessionConf)){
				$aRemoteDir = explode('/',urldecode($aSessionConf['RemoteDirectory']));
				$sProcessedRemoteDir = '1';
				foreach ($aRemoteDir as $sDirectory){
					$sProcessedRemoteDir .= ' ' . strlen($sDirectory);
					if($sDirectory != ''){
						$sProcessedRemoteDir .= ' ' . $sDirectory;
					}
				}
			}else{
				$sProcessedRemoteDir = '';
			}
			$oNodeToAdd = $oDoc->createElement('RemoteDir', $sProcessedRemoteDir);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('Port', isset($aSessionConf['PortNumber']) ? $aSessionConf['PortNumber'] : 22);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('Type', 0);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('Logontype', 1);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('TimezoneOffset', 0);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('PasvMode', 'MODE_DEFAULT');
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('MaximumMultipleConnections', 0);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('EncodingType', 'Auto');
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('BypassProxy', 0);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('SyncBrowsing', 0);
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('Comments');
			$oServer->appendChild($oNodeToAdd);
			$oNodeToAdd = $oDoc->createElement('Name', $sSessionLabel);
			$oServer->appendChild($oNodeToAdd);
			
			// 3 from the last directory in the structure, "stack" directories on onto another
			$aTempStructure = Array();
            if (count($aFoldersToCreate) > 1){
                for($iCptElements = count($aFoldersToCreate) - 1; $iCptElements >= 0; $iCptElements --){
                    if($iCptElements == count($aFoldersToCreate) - 1){
                        $aTempStructure = Array (
                            $aFoldersToCreate[$iCptElements] => Array(
                                 's|' . $sSessionLabel => $oServer
                            )
                        );
                    }else{
                        $aTempStructure = Array ($aFoldersToCreate[$iCptElements] => $aTempStructure);
                    }
                }
            }else{
                // handle the sites not stored into a "folder"
                $aTempStructure = Array('s|' . $sSessionLabel => $oServer);
            }

			// add the directory structure to the global directory structure
			$aDirectoryStructure = array_merge_recursive($aDirectoryStructure, $aTempStructure);
		 	$oDoc->appendChild($oServer);

		}
		
		// 4 within $aDirectoryStructure, there is the folder structure, along with the XML nodes.
		// Now, remains to create <folder> nodes for each level of the array, then add the DOM element present in it.
		processStructure($aDirectoryStructure, $oDoc, $oServers);

	}
	
	if (!$bErrorHasHappened){
		header("Cache-Control: public");
		header("Content-Description: File Transfer");
		header("Content-Disposition: attachment; filename=sitemanager.xml");
		header("Content-Type: application/octet-stream; "); 
		header("Content-Transfer-Encoding: binary");
		echo $oDoc->saveXML();
	}else{
		echo '<span style="color:red;">' . $sErrorToDisplay . '</span>';
	}
	
	unlink($_FILES['userfile']['tmp_name']);
?>
