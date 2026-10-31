<?php
// Historical records are retained; the retired activity endpoint is no longer served.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit('该活动已下线。');
