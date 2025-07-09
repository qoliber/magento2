SET foreign_key_checks = 0;

UPDATE core_config_data SET value='https://jbfurniture.test/' WHERE scope='default' and scope_id='0' and path='web/secure/base_url';
UPDATE core_config_data SET value='https://jbfurniture.test/' WHERE scope='default' and scope_id='0' and path='web/unsecure/base_url';
UPDATE core_config_data SET value='https://justparasols.test/' WHERE scope='websites' and scope_id='2' and path='web/secure/base_link_url';
UPDATE core_config_data SET value='https://justparasols.test/' WHERE scope='websites' and scope_id='2' and path='web/secure/base_url';
UPDATE core_config_data SET value='https://justparasols.test/' WHERE scope='websites' and scope_id='2' and path='web/unsecure/base_link_url';
UPDATE core_config_data SET value='https://justparasols.test/' WHERE scope='websites' and scope_id='2' and path='web/unsecure/base_url';
UPDATE core_config_data SET value='https://justgardensofas.test/' WHERE scope='websites' and scope_id='4' and path='web/secure/base_link_url';
UPDATE core_config_data SET value='https://justgardensofas.test/' WHERE scope='websites' and scope_id='4' and path='web/secure/base_url';
UPDATE core_config_data SET value='https://justgardensofas.test/' WHERE scope='websites' and scope_id='4' and path='web/unsecure/base_link_url';
UPDATE core_config_data SET value='https://justgardensofas.test/' WHERE scope='websites' and scope_id='4' and path='web/unsecure/base_url';
UPDATE core_config_data SET value='https://justgardendining.test/' WHERE scope='stores' and scope_id='5' and path='web/secure/base_link_url';
UPDATE core_config_data SET value='https://justgardendining.test/' WHERE scope='stores' and scope_id='5' and path='web/secure/base_url';
UPDATE core_config_data SET value='https://justgardendining.test/' WHERE scope='stores' and scope_id='5' and path='web/unsecure/base_link_url';
UPDATE core_config_data SET value='https://justgardendining.test/' WHERE scope='stores' and scope_id='5' and path='web/unsecure/base_url';

DELETE FROM core_config_data WHERE path like 'comm100%';

SET foreign_key_checks = 1;
