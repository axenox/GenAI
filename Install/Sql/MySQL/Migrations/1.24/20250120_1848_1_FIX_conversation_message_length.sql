-- UP

ALTER TABLE `exf_ai_message`
    CHANGE `message` `message` longtext NOT NULL,
    CHANGE `data` `data` longtext NULL;

-- DOWN

ALTER TABLE `exf_ai_message`
    CHANGE `message` `message` text NOT NULL,
    CHANGE `data` `data` text NULL;