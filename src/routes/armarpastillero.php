<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

// Add product
$app->post('/api/armarpastillero', function (Request $request, Response $response) {
    $pastillero = $request->getParam('pastillero');

    try {
        // Primero obtiene cuánta dósis de cada droga tiene que tomar el usuario
        $sql = "SELECT SUM(cantidad_mg*7) as dosis_semanal, d.id, nombre FROM droga_x_dosis dxd LEFT JOIN droga d ON dxd.droga_id = d.id WHERE d.pastillero = '$pastillero' GROUP BY d.id";

        // Get db object
        $db = new db();
        // Connect
        $db = $db->connect();

        // Selecciona todas las drogas
        $stmt = $db->query($sql);
        $dosis_semanales = $stmt->fetchAll(PDO::FETCH_OBJ);

        // Luego, obtiene los stocks de las drogas ordenado por fecha de ingreso
        $sql = "SELECT s.id as stock_id, s.droga as droga_id, comprimido, cantidad_doceavos, fecha_ingreso FROM stock s LEFT JOIN droga d ON s.droga = d.id WHERE d.pastillero = '$pastillero' ORDER BY fecha_ingreso ASC";

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

        $newResponse = $response->withStatus(200);
        $body = $response->getBody();
        $body->write('{"status": "success","message": "Stock procesado"}');
        $newResponse = $newResponse->withBody($body);
        return $newResponse;
    } catch (PDOException $e) {
        echo '{"error":{"text": '.$e->getMessage().'}}';
    }
});
