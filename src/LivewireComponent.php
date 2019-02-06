<?php

namespace Livewire;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Fluent;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Opis\Closure\SerializableClosure;

abstract class LivewireComponent
{
    protected $hashes = [];
    protected $exemptFromHashDiffing = [];
    protected $connection;
    protected $component;
    protected $forms;

    protected $propertiesExemptFromHashing = [
        'hashes', 'exemptFromHashDiffing', 'connection',
        'component', 'forms',
    ];

    public function __construct($connection, $component)
    {
        $this->connection = $connection;
        $this->component = $component;
        $this->forms = new LivewireFormCollection;
    }

    abstract public function render();

    public function mounted()
    {
        //
    }

    public function validated($fields)
    {
        $result = [];
        foreach ((array) $fields as $field) {
            $result[$field] = $this->{$field};
        }

        return Validator::make($result, $this->validates)
            ->validate();
    }

    public function syncInput($model, $value)
    {
        if (method_exists($this, 'onSync' . studly_case($model))) {
            $this->{'onSync' . studly_case($model)}($value);
        }

        $this->exemptFromHashDiffing[] = $model;

        $this->{$model} = $value;
    }

    public function clearFormRefreshes()
    {
        foreach ($this->forms->toArray() as $form) {
            $form->refreshed();
        }
    }

    public function beforeAction()
    {
        $this->createHashesForDiffing();
    }

    public function afterAction()
    {
        $this->clearSyncRefreshes();
    }

    public function createHashesForDiffing()
    {
        foreach ($this->getUserDefinedProps() as $property) {
            // For now only has strings and numbers to not be too slow.
            if (is_null($this->{$property}) || is_string($this->{$property}) || is_numeric($this->{$property})) {
                $this->hashes[$property] = crc32($this->{$property});
            }
        }
    }

    public function dirtyInputs()
    {
        $pile = [];
        foreach ($this->hashes as $prop => $hash) {
            if (in_array($prop, $this->exemptFromHashDiffing)) {
                continue;
            }
            // For now only has strings and numbers to not be too slow.

            if (is_null($this->{$prop}) || is_string($this->{$prop}) || is_numeric($this->{$prop})) {
                if (crc32($this->{$prop}) !== $hash) {
                    $pile[] = $prop;
                }
            }
        }

        return $pile;
    }

    public function clearSyncRefreshes()
    {
        $this->hashes = [];
        $this->exemptFromHashDiffing = [];
    }

    public function view($errors = null)
    {
        $errors = $errors ? (new ViewErrorBag)->put('default', $errors) : new ViewErrorBag;

        return $this->render()->with('forms', $this->forms)->with('errors', $errors);
    }

    public function getProps()
    {
        return array_map(function ($prop) {
            return $prop->getName();
        }, (new \ReflectionClass($this))->getProperties());
    }

    public function getUserDefinedProps()
    {
        return array_diff($this->getProps(), $this->propertiesExemptFromHashing);
    }

    public function makeSerializable($callback)
    {
        return new SerializableClosure($callback);
    }

    public function __toString()
    {
        return $this->view();
    }

    public function __sleep()
    {
        // Prepare all callbacks for serialization.
        // PHP cannot serialize closures by default.
        foreach ($props = $this->getProps() as $prop) {
            if ($this->{$prop} instanceof Closure) {
                $this->{$prop} = $this->makeSerializable($this->{$prop});
            }
        }

        return $props;
    }
}