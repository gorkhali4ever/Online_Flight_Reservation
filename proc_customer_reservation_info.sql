CREATE OR REPLACE PROCEDURE customer_reservation_info (
    my_username IN VARCHAR2, 
    p_flightId OUT varchar2,
    p_airlinecode OUT VARCHAR2,
    p_flightnumber OUT number,
    p_flightdate OUT varchar2,
    p_seating_grade OUT VARCHAR2) AS
BEGIN
    OPEN p_result FOR
        SELECT
            R.flightId,
            F.airlinecode,
            F.flightnumber,
            F.flightdate,
            R.seating_grade
        FROM reserves R
        JOIN flight F
            ON R.flightId = F.flightID
        JOIN flightroute FR
            ON FR.airlinecode = F.airlinecode
           AND FR.flightnumber = F.flightnumber
        WHERE R.username = my_username;
END;
/