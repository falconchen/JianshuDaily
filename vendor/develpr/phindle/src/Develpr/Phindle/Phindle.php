<?php namespace Develpr\Phindle;

/**
 *	Main entry point for creating mobi document
 */
class Phindle{

	private $content;
	private $toc;
    private $attributes;
    private $fileHandler;
    private $opfRenderer;
    private $ncxRenderer;

	/**
	 * @var HtmlHelper
	 */
	private $htmlHelper;

    /**
     * @param array $data
     * @param FileHandler $fileHandler
     * @param NcxRenderer $ncxRenderer
     * @param OpfRenderer $opfRenderer
     */
    public function __construct($data = array(), FileHandler $fileHandler = null, NcxRenderer $ncxRenderer = null, OpfRenderer $opfRenderer = null, ContentInterface $tableOfContents = null)
	{
		$this->attributes = $data;

		$this->htmlHelper = false;

		$this->content = array();

		if(is_null($fileHandler))
		    $this->createFileHandler();
        else
            $this->fileHandler = $fileHandler;

		//todo: this shouldn't be here, consider passing in as a parameter?
		$htmlHelper = new HtmlHelper();
		$htmlHelper->setAbsoluteStaticResourcePath($this->getAttribute('staticResourcePath'));
		$htmlHelper->setTempDirectory($this->fileHandler->getTempPath());
		$htmlHelper->setDownloadImages($this->getAttribute('downloadImages')); //will default to false if not set

        $this->htmlHelper = $htmlHelper;

		if(is_null($opfRenderer))
            $this->opfRenderer = new OpfRenderer(new Templatish(), $htmlHelper);

        if(is_null($ncxRenderer))
            $this->ncxRenderer = new NcxRenderer(new Templatish());

        if(is_null($tableOfContents))
            $this->toc = new TableOfContents(new Templatish(), $htmlHelper);

    }


    /**
     * Create a new Mobi document from data provided
     *
     * @throws \Exception
     */
    public function process()
    {
        $result = $this->validate();

        if(count($result) > 0)
            throw new \Exception("Invalid Phindle setup. Additional configuration options required. " . implode('. ', $result));

		$this->generateUniqueId();

        $this->sortContent();

		//Get the first content item
        $this->setAttribute('start',reset($this->content)->getAnchorPath());

        //If a default instance of TableOfContents provided by this package was used then we need to tell
        //the TableOfContents instance about the contents of the Phindle file. It is not required that you use
        //TableOfContents, as long as you implement the ContentsInterface and so in these cases the generate
        //method may not exist.
        if($this->toc instanceof TableOfContents)
        {
            $this->toc->generate($this->content);
        }

        //We will add the table of contents as a "normal" content element as well because it will implement getHtml
        //and so we can write it out as a temporary static file as needed
        $this->addContent($this->toc);
        $this->sortContent();

        $this->setAttribute('toc', $this->toc->getAnchorPath());

		//Now we need to generate and write out the static html files for kindlegen to process
		foreach($this->content as $content)
		{
			$html = $content->getHtml();

			//If ingredients are provided then we'll try to automatically set relative static resource paths in html files
			if($this->htmlHelper && $this->attributeExists('staticResourcePath'))
				$html = $this->htmlHelper->appendRelativeResourcePaths($html);

			/** @var \Develpr\Phindle\ContentInterface $content */
			$this->fileHandler->writeTempFile($content->getUniqueIdentifier() . '.html', $html);
		}

        $this->fileHandler->writeTempFile($this->getAttribute('uniqueId') . '.opf', $this->opfRenderer->render($this->attributes, $this->content, $this->toc));
        $this->fileHandler->writeTempFile($this->getAttribute('uniqueId') . '.ncx', $this->ncxRenderer->render($this->attributes, $this->content));

		$this->generateMobi();

        //Remove all temporary files
        $this->fileHandler->clean();

		return true;
    }

	private function generateMobi()
	{
		if(!$this->getAttribute('kindlegenPath'))
			$kindlegenPath = exec('which kindlegen 2>&1');
		else
			$kindlegenPath = $this->getAttribute('kindlegenPath');

		if(!$kindlegenPath)
			throw new \Exception("The kindlegen command line tool path could not be found. Either set the kindlegenPath attribute, or make sure kindlegen is accessible in system.");

		$command = 'cd ' . $this->fileHandler->getTempPath() . '; ' . $kindlegenPath . ' ' . $this->getAttribute('uniqueId') . '.opf'. ' -c2';

		$output = exec($command . " 2>&1");


		$command = 'cd ' . $this->fileHandler->getTempPath() . '; ' . 'mv ' . $this->getAttribute('uniqueId') . '.mobi ' . $this->getAttribute('path');

		$output = exec($command . " 2>&1");

		//$command = 'cd ' . $this->getAttribute('path'). '; ' . 'mv ' . $this->getAttribute('uniqueId') . '.mobi ' . $this->getAttribute('title').'.mobi';

		//$output = exec($command . " 2>&1");

		return $output;
	}

    public function valid()
    {
        return count($this->validate()) > 0 ? false : true;
    }

    /**
     * Validate that all required parameters are set in the metadata
     *
     * @return array
     */
    public function validate()
    {
        $errors = array();

        if(!$this->attributeExists('title'))
            $errors[] = 'A title must be specified.';

		if(!$this->attributeExists('language'))
            $errors[] = 'A language must be specified, and it should be in the format (example) `en-us`';

        //todo: multiple creators should be configurable
		if(!$this->attributeExists('creator'))
            $errors[] = 'A creator (author) must be specified. ';

		if(!$this->attributeExists('publisher'))
            $errors[] = 'A publisher must be specified. If there is no publisher, use the same as `creator`';

		if(!$this->attributeExists('subject'))
            $errors[] = 'A subject is required - see https://www.bisg.org/complete-bisac-subject-headings-2013-edition';

		if(!$this->attributeExists('description'))
            $errors[] = 'A description is required.';

        return $errors;
    }


	/**
	 * Generate a unique id for this Phindle based on title and current timestamp.
	 *
	 * @return string
	 */
	private function generateUniqueId()
	{
		// $fileTitlePrefix = preg_replace("/[^A-Za-z0-9]/", '', $this->getAttribute('title') . "");

		$fileTitlePrefix = $this->getAttribute('title') ;

		if(strlen($fileTitlePrefix) > 10)
				$fileTitlePrefix = mb_substr($fileTitlePrefix, 0, 10);

		$uniqueId = $fileTitlePrefix . "" . date("ymdHis");

		$this->setAttribute('uniqueId', $uniqueId);

		return $uniqueId;
	}
	
	/**
	 *	Return the path to the mobi file or false if non existent
	 *
	 *	@return mixed
	 */
	public function getMobiPath()
	{
		//If no uniqueId is set, then the mobi file wasn't generated
		if(!$this->getAttribute('uniqueId'))
			return false;

		$mobiPath = substr($this->getAttribute('path'), -1) == '/' ? $this->getAttribute('path') : $this->getAttribute('path') . '/';
		$mobiPath .= $this->getAttribute('uniqueId') . '.mobi';

		return $mobiPath;
	}



	/**
	 *
	 * Add new content to the Phindle document
	 *
	 * @param ContentInterface $content
	 * @return $this
	 * @throws \InvalidArgumentException
	 */
	public function addContent(ContentInterface $content)
	{
		if(!$content instanceof ContentInterface)
			throw new \InvalidArgumentException("Content must implement the ContentInterface");

		$this->content[] = $content;

		return $this;
	}

	/**
	 * Add new Table of Contents (toc) to Phindle document
	 *
	 * @param TableOfContentsInterface $toc
	 * @return $this
	 * @throws \InvalidArgumentException
	 */
	public function addTableOfContents(TableOfContentsInterface $toc)
	{
		if(!$toc instanceof TableOfContentsInterface)
			throw new \InvalidArgumentException("Content must implement the TableOfContentsInterface");

		$this->toc = $toc;

		return $this;
	}

    /**
     * Method used for sorting with usort by position of Content
     *
     * @param ContentInterface $c1
     * @param ContentInterface $c2
     * @return int
     */
    private function sortByPosition(ContentInterface $c1, ContentInterface $c2)
	{
		if($c1->getPosition() == $c2->getPosition())
			return 0;

		return ($c1->getPosition() < $c2->getPosition()) ? -1 : 1;
	}

    /**
     * Sorts the content of the Phindle file based on the order provided by each content's getPosition
     * response.
     *
     * @return $this
     */
    private function sortContent()
	{
		usort($this->content, array($this, 'sortByPosition'));

		return $this;
	}
	
	private function createFileHandler()
	{
		if(!$this->attributeExists('path'))
			throw new \Exception("Unable to create FileHandler without a path supplied");

		if(!$this->attributeExists('tempDirectory'))
			$this->setAttribute('tempDirectory', rand(11111111,999999999));

		$this->fileHandler = new FileHandler($this->attributes['path'], $this->attributes['tempDirectory']);

		return $this->fileHandler;
	}



	private function attributeExists($attribute)
	{
		return !(!array_key_exists($attribute, $this->attributes) || strlen($this->attributes[$attribute]) < 1);
	}


    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute($key)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : null;
    }

    public function setToc(ContentInterface $toc)
    {
        $this->toc = $toc;
    }

    public function getToc()
    {
        return $this->toc;
    }

	/**
	 * @param mixed $htmlHelper
	 */
	public function setHtmlHelper(HtmlHelper $htmlHelper)
	{
		$htmlHelper->setAbsoluteStaticResourcePath($this->getAttribute('staticResourcePath'));
		$htmlHelper->setTempDirectory($this->fileHandler->getTempPath());

		$this->htmlHelper = $htmlHelper;
	}

	/**
	 * @return mixed
	 */
	public function getHtmlHelper()
	{
		return $this->htmlHelper;
	}




}
