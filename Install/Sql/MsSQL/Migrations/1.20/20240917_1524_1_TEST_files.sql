
-- UP

-- @installer.stopOnError(true)

ALTER TABLE ADD path;

UPDATE etl_web_request SET path = CONCAT('etl/web/requests/', oid, '/body.json');

/* this must be an extra batch! It must separate the SQL before it and after it in two batches.
@installer.writeToFiles(
	'etl_web_request', // table
	'oid, request_body, path', // select. These are the placeholder values!
	'axenox.ETL.FILE_STORE', // file DS
	'[#path#]'
	'[#request_body#]' // content
)
*/

ALTER TABLE etl_web_request DROP COLUMN request_body;

-- DOWN

-- @installer.readFromFiles(...)

/* Im Metamodell

=Base64(ATTR1, ' ', ATTR2)

*/