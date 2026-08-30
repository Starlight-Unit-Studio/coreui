SET NAMES utf8mb4;

-- Diese beiden Quellen wurden in fruehen Alpha-Paketen versehentlich als
-- globale RAG-Lite-Inhalte ausgeliefert. Sie enthalten privates Studio-Material
-- und werden deshalb exakt nach ihrer historischen Source-ID entfernt.
DELETE FROM ember_knowledge_chunks
 WHERE source IN ('bibel_v10_4', 'kompendium_v6');

INSERT INTO stu_schema_migrations (version)
VALUES ('007_remove_private_studio_lore')
ON DUPLICATE KEY UPDATE version = VALUES(version);
