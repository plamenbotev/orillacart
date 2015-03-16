<?php

defined('_VALID_EXEC') or die('access denied');

class shopControllerAttributesajax extends controller {

    protected function __default() {
		
       $this->execute("newset");
    }

    protected function newset() {

        $this->getView('attributes');

        $model = $this->getModel('attributes');

        @ob_end_clean();
        ob_start();

        if (isset($_GET['id']) && $model->is_set((int) $_GET['id'])) {
            $id = (int) $_GET['id'];
            $set = $model->loadSet($id);

            $attributes = $model->getSetAttributes($id)->loadObjectList();
            $this->view->assignref('set', $set);
            $this->view->assignref('attributes', $attributes);

            $this->view->editsetform();
        } else {
            $this->view->newsetform();
        }


        $row = new stdClass();
        $row->text = ob_get_contents();
        ob_end_clean();

        $row->status = 1;

        header("HTTP/1.0 200 OK");
        header('Content-type: text/json; charset=utf-8');
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Pragma: no-cache");

        echo json_encode($row);
        die();
    }

}
