<?php namespace Illuminate\Http;

use Illuminate\Support\Contracts\JsonableInterface;

class JsonResponse extends \Symfony\Component\HttpFoundation\JsonResponse {

	use ResponseTrait;

	/**
	 * The json encoding options.
	 *
	 * @var int
	 */
	protected $jsonOptions;

	/**
	 * Constructor.
	 *
	 * @param  mixed  $data
	 * @param  int    $status
	 * @param  array  $headers
	 * @param  int    $options
	*/
	public function __construct($data = null, $status = 200, $headers = array(), $options = 0)
	{
		$this->jsonOptions = $options;

		parent::__construct($data, $status, $headers);
	}

	/**
	 * Get the json_decoded data from the response
	 *
	 * @param  bool  $assoc
	 * @param  int   $depth
	 * @return mixed
	 */
	public function getData($assoc = false, $depth = 512)
	{
		return json_decode((string) $this->data, $assoc, $depth);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setData($data = array()): static
    {
		$this->data = $data instanceof JsonableInterface
								   ? $data->toJson($this->jsonOptions)
								   : json_encode($data, $this->jsonOptions);

		return $this->update();
	}

	/**
	 * Get the JSON encoding options.
	 *
	 * @return int
	 */
	public function getJsonOptions()
	{
		return $this->jsonOptions;
	}

	/**
	 * Set the JSON encoding options.
	 *
	 * @param  int  $options
	 * @return mixed
	 */
	public function setJsonOptions($options)
	{
		$this->jsonOptions = $options;

		return $this->setData($this->getData());
	}

}
