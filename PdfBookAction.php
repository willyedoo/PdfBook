<?php

use MediaWiki\MediaWikiServices;

class PdfBookAction extends Action {

	public function getName() {
		return 'pdfbook';
	}

	/**
	 * Perform the export operation
	 */
	public function show() {
		global $wgPdfBookTab, $wgPdfBookDownload, $wgServer, $wgScript,
			$wgArticlePath, $wgScriptPath, $wgUploadPath, $wgUploadDirectory;

		$user   = $this->getUser();
		$output = $this->getOutput();
		$title  = $this->getTitle();
		$page   = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$book   = $title->getText();
		$opt    = ParserOptions::newFromUser( $user );

		$parser = MediaWikiServices::getInstance()->getParserFactory()->getInstance();

		// Log the export
		$msg = wfMessage( 'pdfbook-log', $user->getUserPage()->getPrefixedText() )->text();
		$log = new LogPage( 'pdf', false );
		$log->addEntry( 'book', $title, $msg, [], $user );

		// Initialise PDF variables
		$htmldoc   = $this->setPropertyFromGlobals( 'HtmlDocPath', '/usr/bin/htmldoc' );
		$format    = $this->setProperty( 'format', '', '' );
		$nothumbs  = $this->setProperty( 'nothumbs', '', '' );
		$notitle   = $this->setProperty( 'notitle', '', '' );
		$layout    = $format == 'single' ? '--webpage' : '--firstpage toc';
		$charset   = $this->setProperty( 'Charset',     'en_US.utf8' );
		$left      = $this->setProperty( 'LeftMargin',  '1cm' );
		$right     = $this->setProperty( 'RightMargin', '1cm' );
		$top       = $this->setProperty( 'TopMargin',   '1.5cm' );
		$bottom    = $this->setProperty( 'BottomMargin', '1.5cm' );
		$font      = $this->setProperty( 'Font',        'Arial' );
		$size      = $this->setProperty( 'FontSize',    '10' );
		$ls        = $this->setProperty( 'FontSpacing', 1.5 );
		$linkcol   = $this->setProperty( 'LinkColour',  '0645ad' );
		$levels    = $this->setProperty( 'TocLevels',   '3' );
		$exclude   = $this->setProperty( 'Exclude',     [] );
		$width     = $this->setProperty( 'Width',       '' );
		$numbering = $this->setProperty( 'Numbering', 'yes' );
		$options   = $this->setPropertyFromGlobals( 'Options',     '' );
		$width     = $width ? "--browserwidth " . escapeshellarg( $width ) : '';
		$comments  = ExtensionRegistry::getInstance()->isLoaded( 'AjaxComments' )
			? $this->setProperty( 'comments', '', false )
			: '';

		if ( !is_array( $exclude ) ) {
			$exclude = preg_split( '/\\s*,\\s*/', $exclude );
		}

		// Generate a list of the articles involved in this doc
		// - this is unconditional so that it can be used in cache key generation
		// Select articles from members if a category or links in content if not
		if ( $format == 'single' || $format == 'html' ) {
			$articles = [ $title ];
		} else {
			$articles = [];
			if ( $title->getNamespace() == NS_CATEGORY ) {
				$db     = MediaWikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_REPLICA );
				$cat    = $db->addQuotes( $title->getDBkey() );
				$result = $db->select(
					'categorylinks',
					'cl_from',
					"cl_to = $cat",
					'PdfBook',
					[ 'ORDER BY' => 'cl_sortkey' ]
				);
				while ( $row = $result->fetchRow() ) {
					$articles[] = Title::newFromID( $row[0] );
				}
			} else {
				$text = $page->getContent()->getNativeData();
				$text = $parser->preprocess( $text, $title, $opt );
				if ( preg_match_all( "/^\\*\\s*\\[{2}\\s*([^|\\]]+)\\s*.*?]{2}/m", $text, $links ) ) {
					foreach ( $links[1] as $link ) {
						$articles[] = Title::newFromText( $link );
					}
				}
			}
		}

		// Create a cache filename from the hash of...

		// ...the query-string of the request,
		$cache = json_encode( $this->getRequest()->getValues() );

		// ...the contents of the rendering code (this script)
		$cache .= file_get_contents( __FILE__ );

		// ...and the latest revision(s) of the article(s)
		foreach ( $articles as $title ) {
			$cache .= '-' . $title->getLatestRevID();
		}

		$cache = $wgUploadDirectory . '/pdf-book-cache-' . md5( $cache );

		// If the file doesn't exist, render the content now
		if ( !file_exists( $cache ) ) {

			// Format the article(s) as a single HTML document with absolute URL's
			$html = '';
			$wgArticlePath = $wgServer . $wgArticlePath;
			$wgPdfBookTab = false;
			$wgScriptPath = $wgServer . $wgScriptPath;
			$wgUploadPath = $wgServer . $wgUploadPath;
			$wgScript = $wgServer . $wgScript;

// Add style for images to fit 90% width and set fonts
			$html .= '<style>
body { font-family: "Georgia", serif; font-size: 12pt; line-height: 1.6; }
h1, h2, h3 { font-family: "Georgia", serif; color: #003366; }
img { max-width: 90%; height: auto; }
.cover-page { page-break-after: always; text-align: center; margin-top: 200px; }
</style>';

// Add cover page if exporting a category
if ( $isCategory ) {
    $categoryName = htmlspecialchars( $title->getText() );
    $coverTitle = "Category: $categoryName";
    $date = date('F j, Y');
    $html .= "<div class='cover-page'>
        <h1>$coverTitle</h1>
        <p>Exported on $date</p>
    </div>";
}
			foreach ( $articles as $title ) {
				if ( is_object( $title ) && $title->exists() ) {
					$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
					$ttext = $title->getPrefixedText();
					if ( !in_array( $ttext, $exclude ) ) {
						$text = $page->getContent()->getNativeData();
						$text = preg_replace( "/<!--([^@]+?)-->/", "@@" . "@@$1@@" . "@@", $text ); // preserve HTML comments

// Remove Semantic MediaWiki highlighter spans and their content
$text = preg_replace(
    '|<span\s+class=["\']smw-highlighter["\'][^>]*>.*?</span>|si',
    '',
    $text
);

						$out = $parser->parse( $text, $title, $opt );
						$text = $out->getText(
							[
								// generate TOC if enough headings and format not 'single'
								'allowTOC' => $format == 'single',
								// remove section-edit links
								'enableSectionEditLinks' => false,
							]
						);

						// Make image urls absolute
						if ( $format == 'html' ) {
							$text = preg_replace( "|(<img[^>]+?src=\")(?=/)|", "$1$wgServer", $text );
						} else {
							$pUrl = parse_url( $wgScriptPath );
							$imgpath = str_replace( '/', '\/', $pUrl['path'] . '/' . basename( $wgUploadDirectory ) );
							$text = preg_replace(
								'| src="' . $imgpath . '([^"]*)|',
								' src="' . $wgUploadDirectory . '$1',
								$text
							);
						}

						// Convert image links from thumbnail to full-size
						if ( $nothumbs == 'true' ) {
							$text = preg_replace( "|images/thumb/(\w+/\w+/[\w\.\-]+).*\"|", "images/$1\"", $text );
						}

						// Remove non-printable areas
						$text = preg_replace( "|<div\s*class=['\"]?noprint[\"']?>.+?</div>|s", "", $text );

						// HTML comments hack
						$text = preg_replace( "|@{4}([^@]+?)@{4}|", "<!--$1-->", $text );

						// Make the doc heading spans in to A tags
						$text = preg_replace_callback(
							"|<span[^>]+class=\"mw-headline\"[^>]*>(.+?)</span>|",
							function ( $m ) {
								return preg_match( '|id="(.+?)"|', $m[0], $n )
									? "<a name=\"$n[1]\">$m[1]</a>"
									: $m[0];
							},
							$text
						);

						$ttext = basename( $ttext );
						$h1 = $notitle ? "" : "<center><h1>$ttext</h1></center>";

						// Add comments if selected and AjaxComments is installed
						$commentsForPDF = '';
						if ( $comments ) {
							$commentResponse = AjaxComments::singleton()->getComments( $title->getArticleID() );
							foreach ( $commentResponse as $comment ) {
								$commentsForPDF .= $comment['html'];
							}
						}
						$html .= "$h1$text\n$commentsForPDF";
					}
				}
			}

			$html = removeSMWTTContent($html);

			// Build the cache file
			if ( $format == 'html' ) {
				file_put_contents( $cache, $html );
			} else {

				// Write the HTML to a tmp file
				if ( !is_dir( $wgUploadDirectory ) ) {
					mkdir( $wgUploadDirectory );
				}
				$file = $wgUploadDirectory . '/' . uniqid( 'pdf-book' );
				file_put_contents( $file, $html );

				// Read customization options from globals, fallback to request, fallback to defaults
				$font      = isset($GLOBALS['wgPdfBookFont'])        ? $GLOBALS['wgPdfBookFont']        : $this->setProperty('Font',        'Arial');
				$size      = isset($GLOBALS['wgPdfBookFontSize'])    ? $GLOBALS['wgPdfBookFontSize']    : $this->setProperty('FontSize',    '8');
				$left      = isset($GLOBALS['wgPdfBookLeftMargin'])  ? $GLOBALS['wgPdfBookLeftMargin']  : $this->setProperty('LeftMargin',  '1cm');
				$right     = isset($GLOBALS['wgPdfBookRightMargin']) ? $GLOBALS['wgPdfBookRightMargin'] : $this->setProperty('RightMargin', '1cm');
				$top       = isset($GLOBALS['wgPdfBookTopMargin'])   ? $GLOBALS['wgPdfBookTopMargin']   : $this->setProperty('TopMargin',   '1cm');
				$bottom    = isset($GLOBALS['wgPdfBookBottomMargin'])? $GLOBALS['wgPdfBookBottomMargin']: $this->setProperty('BottomMargin','1cm');
				// Set logo to your specific path
				$logo      = $GLOBALS['wgResourceBasePath'] . '/images/logo/edf_open_doaat.png';
				$showDate  = isset($GLOBALS['wgPdfBookFooterDate'])  ? $GLOBALS['wgPdfBookFooterDate']  : false;

				// Header: show logo if provided, else default
				$headerHtml = $logo ? "<img src=\"$logo\" height=\"30\"/>" : "...";

				// Footer: show page number and/or date if enabled
				$footerHtml = $showDate ? date('Y-m-d H:i') . ' - .1.' : ".1.";

				// For htmldoc command, set $LOGOIMAGE to the logo path if needed
				$LOGOIMAGE = $logo ? "--logoimage " . escapeshellarg($logo) : "";

				// Build the htmldoc command
				$numbering = $numbering == 'yes' ? '--numbered' : '';
				$footer = $format == 'single' ? "..." : ".1.";
				$toc = $format == 'single' ? "" : " --toclevels " . escapeshellarg( $levels );
				$cmd  = "--left " . escapeshellarg( $left )
					. " --right " . escapeshellarg( $right )
					. " --top " . escapeshellarg( $top )
					. " --bottom " . escapeshellarg( $bottom )
					. " --header " . escapeshellarg( $headerHtml )
					. " --footer " . escapeshellarg( $footerHtml )
					. " --headfootsize 8 --quiet --jpeg --color"
					. " --bodyfont " . escapeshellarg( $font )
					. " --fontsize " . escapeshellarg( $size )
					. " --fontspacing " . escapeshellarg( $ls )
					. " --linkstyle plain --linkcolor " . escapeshellarg( $linkcol )
					. "$toc --no-title $numbering --charset "
					. escapeshellarg( $charset ) . " $options $layout $width "
					. $LOGOIMAGE; // Add $LOGOIMAGE to the command
				$cmd = $format == 'htmltoc'
					? "$htmldoc -t html --format html $cmd " . escapeshellarg( $file ) . " "
					: "$htmldoc -t pdf --format pdf14 $cmd " . escapeshellarg( $file ) . " ";

				// Execute the command outputting to the cache file
				putenv( "HTMLDOC_NOCGI=1" );
				shell_exec( "$cmd > " . escapeshellarg( $cache ) );
				unlink( $file );
			}
		}

		// Output the cache file
		$output->disable();
		if ( $format == 'html' || $format == 'htmltoc' ) {
			header( "Content-Type: text/html" );
			header( "Content-Disposition: attachment; filename=\"$book.html\"" );
		} else {
			header( "Content-Type: application/pdf" );
			if ( $wgPdfBookDownload ) {
				header( "Content-Disposition: attachment; filename=\"$book.pdf\"" );
			} else {
				header( "Content-Disposition: inline; filename=\"$book.pdf\"" );
			}
		}
		readfile( $cache );
	}

	private function setPropertyFromGlobals( $name, $val ) {
		if ( isset( $GLOBALS["wgPdfBook$name"] ) ) {
			$val = $GLOBALS["wgPdfBook$name"];
		}
		return preg_replace( '|[^/-_.a-z]|i', '', $val );
	}

	private function setProperty( $name, $val, $prefix = 'pdf' ) {
		$request = $this->getRequest();
		if ( $request->getText( "$prefix$name" ) ) {
			$val = $request->getText( "$prefix$name" );
		}

		// hack to handle ampersand entities in URL
		if ( $request->getText( "amp;$prefix$name" ) ) {
			$val = $request->getText( "amp;$prefix$name" );
		}

		if ( isset( $GLOBALS["wgPdfBook$name"] ) ) {
			$val = $GLOBALS["wgPdfBook$name"];
		}
		return preg_replace( '|[^/-_.a-z]|i', '', $val );
	}
}

// Define the function outside the class (or make it a static method if you prefer)
function removeSMWTTContent($html) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress HTML5 warnings
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

    $xpath = new DOMXPath($doc);
    foreach ($xpath->query("//*[contains(@class, 'smwttcontent')]") as $node) {
        $node->parentNode->removeChild($node);
    }

    return $doc->saveHTML();
}
