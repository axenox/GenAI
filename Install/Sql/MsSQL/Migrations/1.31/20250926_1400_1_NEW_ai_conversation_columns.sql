-- UP

ALTER TABLE exf_ai_conversation
    ADD devmode TINYINT NULL DEFAULT 0,
      connection_oid BINARY(16) NULL,
      model NVARCHAR(100) NULL;

ALTER TABLE exf_ai_conversation
    ADD CONSTRAINT FK_exf_ai_conversation_connection
        FOREIGN KEY (connection_oid)
            REFERENCES exf_data_connection (oid);

-- DOWN

-- Do not delete new columns!