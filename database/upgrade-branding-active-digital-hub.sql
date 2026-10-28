-- Active Digital Hub branding refresh for existing installations.
--
-- Safe to import more than once. Only the legacy default values are changed;
-- operator-customized names and colours are preserved.
-- Run after backing up the database when updating an existing installation.

UPDATE `settings`
SET `setting_value` = JSON_SET(`setting_value`, '$.value', 'Active Digital Hub')
WHERE `setting_key` IN ('site_name', 'mail_from_name', 'giftcard_sender_name')
  AND JSON_UNQUOTE(JSON_EXTRACT(`setting_value`, '$.value')) = 'MarvySocials';

UPDATE `settings`
SET `setting_value` = JSON_SET(`setting_value`, '$.value', '#1877f2')
WHERE `setting_key` = 'brand_primary_color'
  AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(`setting_value`, '$.value'))) IN ('#6366f1', '#4f46e5');
