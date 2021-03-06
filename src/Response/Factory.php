<?php

namespace Emarref\Vacation\Response;

use Emarref\Http\Response;
use Emarref\Http\Stream;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Psr\Http\Message\IncomingRequestInterface;
use Psr\Http\Message\OutgoingResponseInterface;
use Emarref\Vacation\Error;
use Symfony\Component\Form\FormInterface;

class Factory implements FactoryInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @param SerializerInterface      $serializer
     */
    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * @return SerializationContext
     */
    protected function getSerializationContext()
    {
        return (new SerializationContext())->setSerializeNull(true);
    }

    /**
     * @param mixed $content
     * @return string
     */
    protected function getPayload($content)
    {
        return $this->serializer->serialize($content, 'json', $this->getSerializationContext());
    }

    /**
     * @param int    $statusCode
     * @param string $content
     * @return OutgoingResponseInterface
     */
    protected function buildResponse($statusCode, $content = null)
    {
        $response = new Response();

        $response->setHeader('Content-Type', 'application/json');
        $response->setStatusCode($statusCode);

        if (null !== $content) {
            $body = new Stream($content);
            $response->setBody($body);
        }

        return $response;
    }

    protected function fromChildren(FormInterface $form, $prefix = null)
    {
        $errors = [];

        if ($form->isRoot()) {
            foreach ($form->getErrors() as $error) {
                $errors[] = ['type' => 'global', 'message' => $error->getMessage()];
            }
        }

        foreach ($form->all() as $field => $child) {
            if (!count($child->getErrors()) && !count($child->all())) {
                continue;
            }

            $propertyPath = (string)$child->getPropertyPath();
            $propertyPath = $prefix ? sprintf('%s.%s', $prefix, $propertyPath) : $propertyPath;

            foreach ($child->getErrors() as $error) {
                $errors[] = [
                    'type'    => 'field',
                    'field'   => $propertyPath,
                    'message' => $error->getMessage()
                ];
            }

            $errors = array_merge($errors, $this->fromChildren($child, $propertyPath));
        }

        return $errors;
    }

    public function createFormError(FormInterface $form)
    {
        $errors = $this->fromChildren($form);

        return $this->buildResponse(400, $this->getPayload(['errors' => $errors]));
    }

    /**
     * @param \Exception $exception
     * @return OutgoingResponseInterface
     */
    public function createError(\Exception $exception)
    {
        return $this->buildResponse($exception->getCode(), $this->getPayload(['error' => ['messages' => [$exception->getMessage()]]]));
    }

    /**
     * @param IncomingRequestInterface $request
     * @param mixed                    $content
     * @return OutgoingResponseInterface
     */
    public function create(IncomingRequestInterface $request, $content = null)
    {
        if (null === $content) {
            $statusCode = 204;
        } else {
            switch ($request->getMethod()) {
                case 'POST':
                    $statusCode = 201;
                    break;
                case 'DELETE':
                    $statusCode = 204;
                    break;
                case 'PUT':
                case 'PATCH':
                case 'GET':
                default:
                    $statusCode = 200;
                    break;
            }

            $content  = $this->getPayload($content);
        }

        return $this->buildResponse($statusCode, $content);
    }
}
