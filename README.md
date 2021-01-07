# WinSCPSiteConfigurationToFileZilla
## Introduction
Due to software restrictions at work, I now have to use FileZilla instead of WinSCP.
Given I have many sites, migrating them one by one is not viable.
Therefore I have developed a small PHP script that will convert the WinSCP sites to FileZilla format.

The PHP page will output an upload form, on which you can send your WinSCP.ini file.
The script will do its thing, and propose you to download the matching sitemanager.xml file (in FileZilla for Windows, the xml file is located in %APPDATA%\FileZilla\).

For the password recovery, I have reused the excellent tool developed by YuriMB : https://github.com/YuriMB/WinSCP-Password-Recovery, which I rendered less verbose, only to output the password.

## Usage
It's possible to use this script in your webbrowser if you have a webserver 
installed. Just follow the instructions the page tell you.

Alternatively you can use this script with php from command line.
Example:\
`php index.php filename=PATH_TO_YOUR_FILE\WinSCP.ini > OUTPUT_FILE.xml`
