<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\InputFilter;

use ArrayAccess;
use Traversable;
use Zend\Stdlib\ArrayUtils;
use Zend\Stdlib\InitializableInterface;

class BaseInputFilter implements
    InputFilterInterface,
    UnknownInputsCapableInterface,
    InitializableInterface,
    ReplaceableInputInterface
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @var InputInterface[]|InputFilterInterface[]
     */
    protected $inputs = [];

    /**
     * @var InputInterface[]|InputFilterInterface[]
     */
    protected $invalidInputs;

    /**
     * @var array
     */
    protected $validationGroup;

    /**
     * @var InputInterface[]|InputFilterInterface[]
     */
    protected $validInputs;

    /**
     * This function is automatically called when creating element with factory. It
     * allows to perform various operations (add elements...)
     *
     * @return void
     */
    public function init()
    {
    }

    /**
     * Countable: number of inputs in this input filter
     *
     * Only details the number of direct children.
     *
     * @return int
     */
    public function count()
    {
        return count($this->inputs);
    }

    /**
     * Add an input to the input filter
     *
     * @param  InputInterface|InputFilterInterface $input
     * @param  null|string                         $name Name used to retrieve this input
     * @throws Exception\InvalidArgumentException
     * @return InputFilterInterface
     */
    public function add($input, $name = null)
    {
        if (!$input instanceof InputInterface && !$input instanceof InputFilterInterface) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an instance of %s or %s as its first argument; received "%s"',
                __METHOD__,
                'Zend\InputFilter\InputInterface',
                'Zend\InputFilter\InputFilterInterface',
                (is_object($input) ? get_class($input) : gettype($input))
            ));
        }

        if ($input instanceof InputInterface && (empty($name) || is_int($name))) {
            $name = $input->getName();
        }

        if (isset($this->inputs[$name]) && $this->inputs[$name] instanceof InputInterface) {
            // The element already exists, so merge the config. Please note
            // that this merges the new input into the original.
            $original = $this->inputs[$name];
            $original->merge($input);
            return $this;
        }

        $this->inputs[$name] = $input;
        return $this;
    }

    /**
     * Replace a named input
     *
     * @param  InputInterface|InputFilterInterface $input
     * @param  string                              $name Name of the input to replace
     * @throws Exception\InvalidArgumentException
     * @return self
     */
    public function replace($input, $name)
    {
        if (!$input instanceof InputInterface && !$input instanceof InputFilterInterface) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an instance of %s or %s as its first argument; received "%s"',
                __METHOD__,
                'Zend\InputFilter\InputInterface',
                'Zend\InputFilter\InputFilterInterface',
                (is_object($input) ? get_class($input) : gettype($input))
            ));
        }

        if (!array_key_exists($name, $this->inputs)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s: no input found matching "%s"',
                __METHOD__,
                $name
            ));
        }

        $this->inputs[$name] = $input;
        return $this;
    }

    /**
     * Retrieve a named input
     *
     * @param  string $name
     * @throws Exception\InvalidArgumentException
     * @return InputInterface|InputFilterInterface
     */
    public function get($name)
    {
        if (!array_key_exists($name, $this->inputs)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s: no input found matching "%s"',
                __METHOD__,
                $name
            ));
        }
        return $this->inputs[$name];
    }

    /**
     * Test if an input or input filter by the given name is attached
     *
     * @param  string $name
     * @return bool
     */
    public function has($name)
    {
        return (array_key_exists($name, $this->inputs));
    }

    /**
     * Remove a named input
     *
     * @param  string $name
     * @return InputFilterInterface
     */
    public function remove($name)
    {
        unset($this->inputs[$name]);
        return $this;
    }

    /**
     * Set data to use when validating and filtering
     *
     * @param  array|Traversable $data
     * @throws Exception\InvalidArgumentException
     * @return InputFilterInterface
     */
    public function setData($data)
    {
        if (!is_array($data) && !$data instanceof Traversable) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an array or Traversable argument; received %s',
                __METHOD__,
                (is_object($data) ? get_class($data) : gettype($data))
            ));
        }
        if (is_object($data) && !$data instanceof ArrayAccess) {
            $data = ArrayUtils::iteratorToArray($data);
        }
        $this->data = $data;
        $this->populate();
        return $this;
    }

    /**
     * Is the data set valid?
     *
     * @param  mixed|null $context
     * @throws Exception\RuntimeException
     * @return bool
     */
    public function isValid($context = null)
    {
        $data = $this->getRawValues();
        if (null === $data) {
            throw new Exception\RuntimeException(sprintf(
                '%s: no data present to validate!',
                __METHOD__
            ));
        }

        $inputs = $this->validationGroup ?: array_keys($this->inputs);
        return $this->validateInputs($inputs, $data, $context);
    }

    /**
     * Validate a set of inputs against the current data
     *
     * @param  array      $inputs
     * @param  array      $data
     * @param  mixed|null $context
     * @return bool
     */
    protected function validateInputs(array $inputs, array $data = [], $context = null)
    {
        // backwards compatibility
        if (empty($data)) {
            $data = $this->getRawValues();
        }

        $this->validInputs   = [];
        $this->invalidInputs = [];
        $valid               = true;

        foreach ($inputs as $name) {
            $input      = $this->inputs[$name];

            // make sure we have a value (empty) for validation of context
            if (!array_key_exists($name, $data)) {
                $data[$name] = null;
            }

            // Validate an input filter
            if ($input instanceof InputFilterInterface) {
                if (!$input->isValid($context)) {
                    $this->invalidInputs[$name] = $input;
                    $valid = false;
                    continue;
                }
                $this->validInputs[$name] = $input;
                continue;
            }

            // Validate an input
            if ($input instanceof InputInterface) {
                $inputContext = $context ?: $data;

                if (!$input->isValid($inputContext)) {
                    // Validation failure
                    $this->invalidInputs[$name] = $input;
                    $valid = false;

                    if ($input->breakOnFailure()) {
                        return false;
                    }
                    continue;
                }
                $this->validInputs[$name] = $input;
                continue;
            }
        }

        return $valid;
    }

    /**
     * Provide a list of one or more elements indicating the complete set to validate
     *
     * When provided, calls to {@link isValid()} will only validate the provided set.
     *
     * If the initial value is {@link VALIDATE_ALL}, the current validation group, if
     * any, should be cleared.
     *
     * Implementations should allow passing a single array value, or multiple arguments,
     * each specifying a single input.
     *
     * @param  mixed $name
     * @throws Exception\InvalidArgumentException
     * @return InputFilterInterface
     */
    public function setValidationGroup($name)
    {
        if ($name === self::VALIDATE_ALL) {
            $this->validationGroup = null;
            foreach ($this->getInputs() as $input) {
                if ($input instanceof InputFilterInterface) {
                    $input->setValidationGroup(self::VALIDATE_ALL);
                }
            }
            return $this;
        }

        if (is_array($name)) {
            $inputs = [];
            foreach ($name as $key => $value) {
                if (! $this->has($key)) {
                    $inputs[] = $value;
                    continue;
                }

                $inputs[] = $key;

                if (! $this->inputs[$key] instanceof InputFilterInterface) {
                    throw new Exception\InvalidArgumentException(
                        sprintf(
                            'Input "%s" must implement InputFilterInterface',
                            $key
                        )
                    );
                }

                // Recursively populate validation groups for sub input filters
                $this->inputs[$key]->setValidationGroup($value);
            }
        } else {
            $inputs = func_get_args();
        }

        if (! empty($inputs)) {
            $this->validateValidationGroup($inputs);
            $this->validationGroup = $inputs;
        }

        return $this;
    }

    /**
     * Return a list of inputs that were invalid.
     *
     * Implementations should return an associative array of name/input pairs
     * that failed validation.
     *
     * @return InputInterface[]
     */
    public function getInvalidInput()
    {
        return (is_array($this->invalidInputs) ? $this->invalidInputs : []);
    }

    /**
     * Return a list of inputs that were valid.
     *
     * Implementations should return an associative array of name/input pairs
     * that passed validation.
     *
     * @return InputInterface[]
     */
    public function getValidInput()
    {
        return (is_array($this->validInputs) ? $this->validInputs : []);
    }

    /**
     * Retrieve a value from a named input
     *
     * @param  string $name
     * @throws Exception\InvalidArgumentException
     * @return mixed
     */
    public function getValue($name)
    {
        if (!array_key_exists($name, $this->inputs)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects a valid input name; "%s" was not found in the filter',
                __METHOD__,
                $name
            ));
        }
        $input = $this->inputs[$name];

        if ($input instanceof InputFilterInterface) {
            return $input->getValues();
        }

        return $input->getValue();
    }

    /**
     * Return a list of filtered values
     *
     * List should be an associative array, with the values filtered. If
     * validation failed, this should raise an exception.
     *
     * @return array
     */
    public function getValues()
    {
        $inputs = $this->validationGroup ?: array_keys($this->inputs);
        $values = [];
        foreach ($inputs as $name) {
            $input = $this->inputs[$name];

            if ($input instanceof InputFilterInterface) {
                $values[$name] = $input->getValues();
                continue;
            }
            $values[$name] = $input->getValue();
        }
        return $values;
    }

    /**
     * Retrieve a raw (unfiltered) value from a named input
     *
     * @param  string $name
     * @throws Exception\InvalidArgumentException
     * @return mixed
     */
    public function getRawValue($name)
    {
        if (!array_key_exists($name, $this->inputs)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects a valid input name; "%s" was not found in the filter',
                __METHOD__,
                $name
            ));
        }
        $input = $this->inputs[$name];
        return $input->getRawValue();
    }

    /**
     * Return a list of unfiltered values
     *
     * List should be an associative array of named input/value pairs,
     * with the values unfiltered.
     *
     * @return array
     */
    public function getRawValues()
    {
        $values = [];
        foreach ($this->inputs as $name => $input) {
            if ($input instanceof InputFilterInterface) {
                $values[$name] = $input->getRawValues();
                continue;
            }

            $values[$name] = $input->getRawValue();
        }
        return $values;
    }

    /**
     * Return a list of validation failure messages
     *
     * Should return an associative array of named input/message list pairs.
     * Pairs should only be returned for inputs that failed validation.
     *
     * @return array
     */
    public function getMessages()
    {
        $messages = [];
        foreach ($this->getInvalidInput() as $name => $input) {
            $messages[$name] = $input->getMessages();
        }

        return $messages;
    }

    /**
     * Ensure all names of a validation group exist as input in the filter
     *
     * @param  array $inputs
     * @return void
     * @throws Exception\InvalidArgumentException
     */
    protected function validateValidationGroup(array $inputs)
    {
        foreach ($inputs as $name) {
            if (!array_key_exists($name, $this->inputs)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    'setValidationGroup() expects a list of valid input names; "%s" was not found',
                    $name
                ));
            }
        }
    }

    /**
     * Populate the values of all attached inputs
     *
     * @return void
     */
    protected function populate()
    {
        foreach (array_keys($this->inputs) as $name) {
            $input = $this->inputs[$name];

            if ($input instanceof CollectionInputFilter) {
                $input->clearValues();
                $input->clearRawValues();
            }

            if (!isset($this->data[$name])) {
                // No value; clear value in this input
                if ($input instanceof InputFilterInterface) {
                    $input->setData([]);
                    continue;
                }

                if ($input instanceof ArrayInput) {
                    $input->setValue([]);
                    continue;
                }

                $input->setValue(null);
                continue;
            }

            $value = $this->data[$name];

            if ($input instanceof InputFilterInterface) {
                $input->setData($value);
                continue;
            }

            $input->setValue($value);
        }
    }

    /**
     * Is the data set has unknown input ?
     *
     * @throws Exception\RuntimeException
     * @return bool
     */
    public function hasUnknown()
    {
        if (null === $this->data) {
            throw new Exception\RuntimeException(sprintf(
                '%s: no data present!',
                __METHOD__
            ));
        }

        $data   = array_keys($this->data);
        $inputs = array_keys($this->inputs);
        $diff   = array_diff($data, $inputs);
        if (!empty($diff)) {
            return count(array_intersect($diff, $inputs)) == 0;
        }

        return false;
    }

    /**
     * Return the unknown input
     *
     * @throws Exception\RuntimeException
     * @return array
     */
    public function getUnknown()
    {
        if (null === $this->data) {
            throw new Exception\RuntimeException(sprintf(
                '%s: no data present!',
                __METHOD__
            ));
        }

        $data   = array_keys($this->data);
        $inputs = array_keys($this->inputs);
        $diff   = array_diff($data, $inputs);

        $unknownInputs = [];
        $intersect     = array_intersect($diff, $data);
        if (!empty($intersect)) {
            foreach ($intersect as $key) {
                $unknownInputs[$key] = $this->data[$key];
            }
        }

        return $unknownInputs;
    }

    /**
     * Get an array of all inputs
     *
     * @return array
     */
    public function getInputs()
    {
        return $this->inputs;
    }

    /**
     * Merges the inputs from an InputFilter into the current one
     *
     * @param BaseInputFilter $inputFilter
     *
     * @return self
     */
    public function merge(BaseInputFilter $inputFilter)
    {
        foreach ($inputFilter->getInputs() as $name => $input) {
            $this->add($input, $name);
        }

        return $this;
    }
}
