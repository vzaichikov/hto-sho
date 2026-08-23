<?php

namespace App;

enum HarnessEntryKind: string
{
    case Action = 'action';
    case Question = 'question';
    case Answer = 'answer';
    case Llm = 'llm';
    case Mcp = 'mcp';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Action => 'Дія',
            self::Question => 'Питання',
            self::Answer => 'Відповідь',
            self::Llm => 'LLM',
            self::Mcp => 'MCP',
            self::Error => 'Помилка',
        };
    }
}
