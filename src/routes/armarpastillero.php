<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

// Add product
$app->post('/api/armarpastillero', function (Request $request, Response $response) {
    // Verify if the auth header is available
    if ($request->getHeaders()['HTTP_AUTHORIZATION']) {
        // If the header is available, get the token
        $access_token = $request->getHeaders()['HTTP_AUTHORIZATION'][0];
        $access_token = explode(" ", $access_token)[1];
        // Find the access token, if a user is returned, post the products
        if (!empty($access_token)) {
            $user_found = verifyToken($access_token);
            // Verify that there is a user logged in
            if (!empty($user_found)) {
                $pastillero_id = $request->getParam('pastillero');

                // Verifica que haya enviado un pastillero
                if ($pastillero_id) {


                // verifica que el pastillero exista



                    $sql="SELECT * FROM pastillero WHERE id = $pastillero_id";

                    $db = new db();
                    $db = $db->connect();

                    // Selecciona todas las drogas
                    $stmt = $db->query($sql);
                    $pastilleros = $stmt->fetchAll(PDO::FETCH_OBJ);

                    if (count($pastilleros)>0) {

                    // Si el pastillero existe, verifica que el usuario tenga permisos de escritura
                        $usuario_id = $user_found[0]->usuario_id;
                        $permisos_usuario = verificarPermisosUsuarioPastillero($usuario_id, $pastillero_id);

                        // Como es un POST verifica permisos de escritura
                        if ($permisos_usuario->acceso_edicion_pastillero) {
                            try {
                                // Primero obtiene cuánta dósis de cada droga tiene que tomar el usuario
                                $sql = "SELECT SUM(cantidad_mg*7) as dosis_semanal, d.id, nombre FROM droga_x_dosis dxd LEFT JOIN droga d ON dxd.droga_id = d.id WHERE d.pastillero_id = '$pastillero_id' GROUP BY d.id";

                                // Selecciona todas las drogas
                                $stmt = $db->query($sql);
                                $dosis_semanales = $stmt->fetchAll(PDO::FETCH_OBJ);

                                // Luego, obtiene los stocks de las drogas ordenado por fecha de ingreso
                                $sql = "SELECT s.id as stock_id, s.droga_id as droga_id, comprimido, cantidad_doceavos, ingreso_dttm FROM stock s LEFT JOIN droga d ON s.droga_id = d.id WHERE d.pastillero_id = '$pastillero_id' ORDER BY ingreso_dttm ASC";

                                $stmt = $db->query($sql);
                                $stocks = $stmt->fetchAll(PDO::FETCH_OBJ);
                                // Para cada stock, calcula cuántos comprimidos tiene que descontar y lo hace
                                foreach ($dosis_semanales as $dosis) {
                                    foreach ($stocks as $stock) {
                                        if ($stock->droga_id == $dosis->id) {
                                            // Si entra acá es porque encontró la el stock y la dósis de la misma droga
                                            // Verifica que haya suficiente stock en el ingreso más antíguo
                                            $cantidad_comprimidos_en_stock = $stock->cantidad_doceavos;
                                            $concentracion_comprimidos_en_stock = $stock->comprimido;
                                            $dosis_semanal_de_la_droga = $dosis->dosis_semanal;

                                            if ($cantidad_comprimidos_en_stock / 12 * $concentracion_comprimidos_en_stock >= $dosis_semanal_de_la_droga) {
                                                // Si hay suficiente droga como para completar el pastillero
                                                // Calcula cuántos comprimidos tiene que eliminar y edita la tabla
                                                $comprimidos_a_eliminar = 12 * $dosis_semanal_de_la_droga / $concentracion_comprimidos_en_stock;

                                                $cantidad_stock_resultante = floor($cantidad_comprimidos_en_stock - $comprimidos_a_eliminar);

                                                $sql = "UPDATE stock SET cantidad_doceavos = $cantidad_stock_resultante WHERE id = $stock->stock_id";
                                                $stmt = $db->query($sql);
                                                $stmt->execute();

                                                $dosis->dosis_semanal = 0;
                                            } else {
                                                // Si no hay suficiente en este stock como para completar
                                                // el pastillero,
                                                // elimina toda la droga y descuenta de la dósis la cantidad
                                                // eliminada. Luego sigue con el siguiente stock
                                                $sql = "UPDATE stock SET cantidad_doceavos = 0 WHERE id = $stock->stock_id";

                                                $stmt = $db->query($sql);
                                                $stmt->execute();

                                                $dosis->dosis_semanal = $dosis_semanal_de_la_droga - ($cantidad_comprimidos_en_stock * $concentracion_comprimidos_en_stock);
                                            }
                                        }
                                    }
                                }


                                // Luego de procesar todo el stock, borro los registros que
                                // Tengan 0 cantidad

                                $sql="DELETE FROM stock WHERE cantidad_doceavos = 0";
                                $stmt = $db->query($sql);
                                $stmt->execute();

                                $db = null;
                                return messageResponse($response, "Stock procesado exitosamente", 200);
                            } catch (PDOException $e) {
                                $db = null;
                                return messageResponse($response, $e->getMessage(), 503);
                            }
                        } else {  // if ($permisos_usuario->acceso_lectura_pastillero) {
                            $db = null;
                            return messageResponse($response, 'No tiene permisos para editar el pastillero seleccionado', 403);
                        }
                    } else {  //   if (count($pastilleros)>0) {
                        $db = null;
                        return messageResponse($response, 'El pastillero seleccionado no existe', 404);
                    }
                } else {   //   if ($pastillero_id) {
                    $db = null;
                    return messageResponse($response, 'Campos incorrectos', 401);
                }
            } else {  // if (!empty($user_found)) {
                $db = null;
                return messageResponse($response, 'Error de login, usuario no encontrado', 401);
            }
        } else { // if (!empty($access_token)) {
            $db = null;
            return messageResponse($response, 'Error de login, falta access token', 401);
        }
    } else { // if ($request->getHeaders()['HTTP_AUTHORIZATION']) {
        $db = null;
        return messageResponse($response, 'Error de encabezado HTTP', 401);
    }
});
