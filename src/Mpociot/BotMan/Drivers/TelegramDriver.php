<?php

namespace Mpociot\BotMan\Drivers;

use Illuminate\Support\Collection;
use Mpociot\BotMan\Answer;
use Mpociot\BotMan\Message;
use Mpociot\BotMan\Question;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramDriver extends Driver
{
    /** @var Collection|ParameterBag */
    protected $payload;

    /** @var Collection */
    protected $event;

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->event = collect($this->payload->get('message'));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return (! is_null($this->event->get('from')) || ! is_null($this->payload->get('callback_query'))) && ! is_null($this->payload->get('update_id'));
    }

    /**
     * @param  Message $message
     * @return Answer
     */
    public function getConversationAnswer(Message $message)
    {
        if ($this->payload->get('callback_query') !== null) {
            $callback = collect($this->payload->get('callback_query'));

            return Answer::create($callback->get('data'))
                ->setInteractiveReply(true)
                ->setValue($callback->get('data'));
        }

        return Answer::create($message->getMessage());
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if ($this->payload->get('callback_query') !== null) {
            $callback = collect($this->payload->get('callback_query'));

            return [new Message($callback->get('data'), $callback->get('message')['chat']['id'], $callback->get('from')['id'])];
        } else {
            return [new Message($this->event->get('text'), $this->event->get('chat')['id'], $this->event->get('from')['id'])];
        }
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return $this->event->has('entities');
    }

    /**
     * Convert a Question object into a valid Facebook
     * quick reply response object.
     *
     * @param Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $replies = collect($question->getButtons())->map(function ($button) {
            return [
                'text' => $button['text'],
                'callback_data' => $button['value'],
            ];
        });

        return $replies->toArray();
    }

    /**
     * @param string|Question $message
     * @param Message $matchingMessage
     * @param array $additionalParameters
     * @return Response
     */
    public function reply($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = array_merge([
            'chat_id' => $matchingMessage->getUser(),
        ], $additionalParameters);
        /*
         * If we send a Question with buttons, ignore
         * the text and append the question.
         */
        if ($message instanceof Question) {
            $parameters['text'] = $message->getText();
            $parameters['reply_markup'] = json_encode([
                'inline_keyboard' => [$this->convertQuestion($message)],
            ], true);
        } else {
            $parameters['text'] = $message;
        }

        return $this->http->post('https://api.telegram.org/bot'.$this->config->get('telegram_token').'/sendMessage', [], $parameters);
    }
}
